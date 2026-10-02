import asyncio
import base64
import contextvars
import functools
import http
import inspect
import io
import json
import math
import re
import secrets
import threading
import urllib.parse

from .engine import DEFAULT_CREDITS, DEFAULT_RELAY, DEFAULT_RPC, GRANTED_BY, Engine
from .engine.meter import time_text
from .engine.pricing import FreeLimit, deadline_ms, metered, price_of, running, units, usd
from .engine.pricing import describe as describe_price
from .engine.settlement import StateFile
from .grants import gate_proof, key_address, new_key, sign_grant

META_PAYMENT = "x402/payment"
META_RESPONSE = "x402/payment-response"
META_GRANTED_BY = "zeam-pass/granted-by"
META_PRICE = "zeam-pass/price"
META_LINE = "zeam-pass/line"
META_METER = "zeam-pass/meter"
LINE_OPS = ("open", "prove", "on", "off", "status", "close")
CHANNEL = re.compile(r"^0x[0-9a-fA-F]{64}$")
MCP_VERSIONS = ("2025-11-25", "2025-06-18", "2025-03-26", "2024-11-05")
MAX_BODY = 1024 * 1024
CORS = {"access-control-allow-origin": "*", "access-control-expose-headers": "PAYMENT-REQUIRED, PAYMENT-RESPONSE, X-Pass-Granted-By, X-Pass-Ms-Remaining, X-Pass-Ms-Elapsed"}
PREFLIGHT = {**CORS, "access-control-allow-methods": "GET, POST, OPTIONS",
             "access-control-allow-headers": "content-type, payment-signature, x-payment, x-line, x-grant, mcp-protocol-version, mcp-session-id",
             "access-control-max-age": "600"}
HOW = {"paywall": "Paid per call with x402", "gate": "Admitted keys only, proven by a zero-value x402 signature. Nothing is paid.",
       "both": "Admitted keys only, paid per call with x402"}
RESULT_NOT_JSON = "the result is not valid JSON (a non-finite number or a non-JSON value); nothing was charged"
TYPES = {"string": (str,), "integer": (int,), "number": (int, float), "boolean": (bool,),
         "object": (dict,), "array": (list,), "null": (type(None),)}

__all__ = ["Pass", "Engine", "deadline_ms", "gate_proof", "key_address", "new_key", "sign_grant", "units"]


def _b64(obj):
    return base64.b64encode(json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode()).decode()


def _unb64(value):
    try:
        return json.loads(base64.b64decode(value).decode())
    except Exception:
        return None


def _reject_constant(name):
    raise ValueError(f"{name} is not a JSON number")


def _finite(text):
    value = float(text)
    if not math.isfinite(value):
        raise ValueError(f"{text} is out of range")
    return value


def _faithful(value):
    if value is None or isinstance(value, (str, bool, int)):
        return True
    if isinstance(value, float):
        return math.isfinite(value)
    if isinstance(value, (list, tuple)):
        return all(_faithful(v) for v in value)
    if isinstance(value, dict):
        return all(isinstance(k, str) and _faithful(v) for k, v in value.items())
    return False


def _serializes(value):
    try:
        json.dumps(value, allow_nan=False)
    except (TypeError, ValueError, RecursionError):
        return False
    return _faithful(value)


def _loads(raw):
    return json.loads(raw, parse_constant=_reject_constant, parse_float=_finite)


def _is(value, kind):
    if kind == "integer" and isinstance(value, float):
        return value.is_integer()
    if isinstance(value, bool) and kind != "boolean":
        return False
    return isinstance(value, TYPES.get(kind, (object,)))


def _number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _check_value(key, spec, value):
    kinds = spec.get("type")
    kinds = [kinds] if isinstance(kinds, str) else kinds if isinstance(kinds, list) else []
    if kinds and not any(_is(value, k) for k in kinds):
        return f"argument {key} must be {' or '.join(map(str, kinds))}"
    if isinstance(spec.get("enum"), list) and value not in spec["enum"]:
        return f"argument {key} must be one of {json.dumps(spec['enum'])}"
    if _number(value):
        if _number(spec.get("minimum")) and value < spec["minimum"]:
            return f"argument {key} must be at least {spec['minimum']}"
        if _number(spec.get("maximum")) and value > spec["maximum"]:
            return f"argument {key} must be at most {spec['maximum']}"
    if isinstance(value, str):
        if _number(spec.get("minLength")) and len(value) < spec["minLength"]:
            return f"argument {key} must be at least {spec['minLength']} characters"
        if _number(spec.get("maxLength")) and len(value) > spec["maxLength"]:
            return f"argument {key} must be at most {spec['maxLength']} characters"
    return None


def _check_object(schema, args, prefix, nested):
    required = schema.get("required") if isinstance(schema.get("required"), list) else []
    missing = [k for k in required if k not in args]
    if missing:
        return f"missing required argument: {prefix}{missing[0]}"
    props = schema.get("properties") if isinstance(schema.get("properties"), dict) else {}
    for key, value in args.items():
        spec = props.get(key)
        if not isinstance(spec, dict):
            continue
        wrong = _check_value(prefix + str(key), spec, value)
        if wrong:
            return wrong
        if nested and isinstance(value, dict):
            wrong = _check_object(spec, value, f"{prefix}{key}.", False)
            if wrong:
                return wrong
    return None


def _accepted(fn, args):
    try:
        params = inspect.signature(fn).parameters.values()
    except (TypeError, ValueError):
        return args
    if any(p.kind == p.VAR_KEYWORD for p in params):
        return args
    names = {p.name for p in params if p.kind in (p.POSITIONAL_OR_KEYWORD, p.KEYWORD_ONLY)}
    return {k: v for k, v in args.items() if k in names}


def _validate(schema, args):
    if not isinstance(args, dict):
        return "the arguments must be an object"
    return _check_object(schema if isinstance(schema, dict) else {}, args, "", True)


def _said(out):
    content = out.get("content") if isinstance(out, dict) else None
    texts = [c["text"] for c in content if isinstance(c, dict) and isinstance(c.get("text"), str)] if isinstance(content, list) else []
    return "\n".join(texts) or "the tool reported an error"


def _text(obj):
    return [{"type": "text", "text": obj if isinstance(obj, str) else json.dumps(obj)}]


def _reason(status):
    try:
        return http.HTTPStatus(status).phrase
    except ValueError:
        return "Status"


def _first(headers, names):
    for n in names:
        v = headers.get(n)
        if isinstance(v, str) and v.strip() != "":
            return v.strip()
    return ""


def _out_of_time(on_line, ms=None):
    if on_line:
        return {"error": "out_of_time", "message": "the line ran out of time during the call. Its time is spent. Buy time: buy_time."}
    return {"error": "out_of_time", "message": f"the call ran past the {ms} ms its price buys. Nothing was charged. Buy time and call on a line."}


def _blocks(args):
    b = args.get("blocks") if isinstance(args, dict) else None
    if isinstance(b, float) and b.is_integer():
        b = int(b)
    return b if isinstance(b, int) and not isinstance(b, bool) and 1 <= b <= 9007199254740991 else 1


def _int(value):
    try:
        return int(float(value)) if value is not None and not isinstance(value, bool) else 0
    except (TypeError, ValueError, OverflowError):
        return 0


def _failure(body):
    return {"isError": True, "structuredContent": body, "content": _text(body)}


def _failed(out, tool):
    if isinstance(out.get("structuredContent"), dict):
        return out
    return {**out, "isError": True, "structuredContent": {"error": "tool_failed", "tool": tool, "message": _said(out)}}


def _settled_meta(headers):
    meta = {}
    settled = _unb64(headers["PAYMENT-RESPONSE"]) if headers.get("PAYMENT-RESPONSE") else None
    if settled:
        meta[META_RESPONSE] = settled
    if isinstance(headers.get(GRANTED_BY), str):
        meta[META_GRANTED_BY] = headers[GRANTED_BY]
    return meta


class _RpcError(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


class Pass:

    def __init__(self, name, payout=None, mode="paywall", price=None, site=None, admit=None, relay=DEFAULT_RELAY,
                 credits=DEFAULT_CREDITS, rpc=DEFAULT_RPC, state_dir=None, server_name=None, version="1.0.3", on_empty="refuse",
                 fee_recipient=None, credit_issuer=None, refund_url=None, tick_seconds=60, relay_transport=None,
                 credits_http=None, settings=None, contact=None, payout_is_fee_recipient=False, prices=None, free=None, free_limit=None, time=None):
        self.name = name
        self.server_name = server_name or name
        self.version = version
        self.site = site.rstrip("/") if site else None
        self.engine = Engine(name, payout, mode=mode, price=price, site=self.site, admit=admit, relay=relay, credits=credits,
                             rpc=rpc, state_dir=state_dir, fee_recipient=fee_recipient, credit_issuer=credit_issuer,
                             on_empty=on_empty, refund_url=refund_url, relay_transport=relay_transport,
                             credits_http=credits_http, settings=settings, tick_seconds=tick_seconds, contact=contact,
                             payout_is_fee_recipient=payout_is_fee_recipient, prices=prices, free=free, free_limit=free_limit, time=time)
        self._free_limit = FreeLimit(self.engine.free_limit, self.engine._now_ms)
        self._free_file = StateFile(self.engine.state_dir, "free.json") if self.engine.free_limit else None
        self._tools = {}
        self.time = self.engine.time
        self.meter = self.engine.meter
        if self.time:
            self._builtins()
        self.engine.start()

    def _builtins(self):
        t = self.time
        meter = self.meter

        def buy_time(blocks=1):
            n = _blocks({"blocks": blocks})
            box = running() or {}
            cid = box.get("channelId")
            st = meter.status(cid)
            return {"channelId": cid, "boughtMs": n * t["blockMs"], "msRemaining": st["msRemaining"] + n * t["blockMs"],
                    "blockMs": t["blockMs"], "paidUSD": usd(n * t["blockMicro"])}

        def line(**args):
            return self._line_tool(args, "")

        self._tools["buy_time"] = {
            "name": "buy_time", "builtin": "buy_time", "run": buy_time, "price": None, "unit": None, "free": False,
            "description": f"Buys line time: ${usd(t['blockMicro'])} per {t['blockMs']} ms block, for the channel that pays. Then open a line.",
            "inputSchema": {"type": "object", "properties": {"blocks": {"type": "integer", "minimum": 1, "maximum": t["maxBlocks"],
                                                                        "description": f"blocks of {t['blockMs']} ms; default 1"}}},
        }
        self._tools["line"] = {
            "name": "line", "builtin": "line", "run": line, "price": None, "unit": None, "free": True,
            "description": ("A line spends bought time without a payment per call. op: open {channelId} returns a message to sign "
                            "with the payer key; prove {channelId, nonce, signature} returns the credential; on, off, status, close {credential}."),
            "inputSchema": {"type": "object", "properties": {"op": {"enum": list(LINE_OPS)}, "channelId": {"type": "string"}, "nonce": {"type": "string"},
                                                             "signature": {"type": "string"}, "credential": {"type": "string"}}, "required": ["op"]},
        }

    @property
    def mode(self):
        return self.engine.mode

    def tick(self):
        return self.engine.tick()

    def status(self):
        return self.engine.status()

    def payout_now(self):
        return self.engine.payout_now()

    def tool(self, name=None, description="", input_schema=None, price=None, unit=None, free=False, meter=None):
        def register(fn):
            t = {
                "name": name or fn.__name__,
                "description": description or (fn.__doc__ or "").strip(),
                "inputSchema": input_schema or {"type": "object"},
                "run": fn,
                "price": price,
                "unit": unit,
                "free": free is True,
            }
            if (self._tools.get(t["name"]) or {}).get("builtin"):
                raise ValueError(f"Pass: {t['name']} is a built-in tool")
            if meter is not None and meter != "time":
                raise ValueError('Pass: meter is "time"')
            if meter == "time":
                if not self.time or self._is_free(t):
                    raise ValueError(f"Pass: {t['name']} meters time; set the seller's time option, and do not make it free")
                t["meter"] = "time"
            if not self._is_free(t) and self.mode != "gate" and (self._own_price(t) or not callable(self.engine.prices)):
                self._price_for(t)
            self._tools[t["name"]] = t
            return fn
        return register

    def _is_free(self, tool):
        return tool.get("free") is True or tool["name"] in self.engine.free

    @staticmethod
    def _own_price(tool):
        return tool.get("price") is not None or tool.get("unit") is not None

    def _price_for(self, tool, args=None):
        if tool.get("builtin") == "buy_time":
            return {"micro": _blocks(args) * self.time["blockMicro"], "unitMicro": None}
        fallback = self.engine.price_micro
        prices = self.engine.prices
        if self._own_price(tool):
            return price_of({"price": tool.get("price"), "unit": tool.get("unit")}, fallback)
        if callable(prices):
            return price_of(prices(tool["name"], args if args is not None else {}), fallback)
        return price_of(prices.get(tool["name"]) if isinstance(prices, dict) else None, fallback)

    def _price_tag(self, tool):
        if tool.get("builtin") == "line":
            return {"usd": "0", "per": "call", "free": True}
        if tool.get("builtin") == "buy_time":
            return {"usd": usd(self.time["blockMicro"]), "per": "block", "blockMs": self.time["blockMs"], "maxBlocks": self.time["maxBlocks"]}
        if tool.get("meter") == "time" and not (callable(self.engine.prices) and not self._own_price(tool)):
            p = self._price_for(tool)
            return {"per": "time", "blockUSD": usd(self.time["blockMicro"]), "blockMs": self.time["blockMs"],
                    "callUSD": usd(p["micro"]), "callMs": self.meter.call_ms(p["micro"])}
        if self._is_free(tool):
            return describe_price(None, free=True, per_hour=self.engine.free_limit)
        if self.mode == "gate":
            return {"usd": "0", "per": "call"}
        if not self._own_price(tool) and callable(self.engine.prices):
            return describe_price(None, varies=True)
        return describe_price(self._price_for(tool))

    def _listing(self, base=""):
        if self.mode == "gate":
            return {}
        out = {"prices": {t["name"]: self._price_tag(t) for t in self._tools.values()}}
        if self.time:
            out["time"] = time_text(self.time, base)
        return out

    def tools(self):
        return [self._describe(t) for t in self._tools.values()]

    def _describe(self, tool):
        return {"name": tool["name"], "description": tool["description"], "inputSchema": tool["inputSchema"], "_meta": {META_PRICE: self._price_tag(tool)}}

    def _public(self, url):
        if not self.site or not url:
            return url
        called, site = urllib.parse.urlsplit(url), urllib.parse.urlsplit(self.site)
        return urllib.parse.urlunsplit((site.scheme, site.netloc, called.path, called.query, ""))

    def _run(self, tool, args):
        try:
            out = tool["run"](**_accepted(tool["run"], args))
        except TypeError as e:
            return False, f"bad arguments: {e}", 400
        except Exception as e:
            return False, str(e), 500
        if isinstance(out, dict) and out.get("isError") is True:
            return False, out, 500
        if not _serializes(out):
            return False, RESULT_NOT_JSON, 500
        return True, out, None

    def _within(self, ms, tool, args):
        result = {}
        ctx = contextvars.copy_context()

        def work():
            result["out"] = self._run(tool, args)

        worker = threading.Thread(target=ctx.run, args=(work,), name=f"zeam-pass-{tool['name']}", daemon=True)
        worker.start()
        worker.join(max(0, ms) / 1000)
        return None if worker.is_alive() or "out" not in result else result["out"]

    def _bounded(self, ms, tool, args):
        deadline = self.engine._now_ms() + ms
        box = running()
        if box is not None:
            box["deadline"] = deadline
        out = self._within(ms, tool, args)
        if out is None or (not out[0] and self.engine._now_ms() >= deadline):
            return False, _out_of_time(False, ms), 402
        return out

    def _bought(self, tool, args):
        if tool.get("builtin") != "buy_time":
            return None
        ms = _blocks(args) * self.time["blockMs"]
        return {"credit": lambda hold: self.meter.credit(hold["channelId"], ms, hold["pendingId"]),
                "undo": lambda hold: self.meter.uncredit(hold["channelId"], ms, hold["pendingId"])}

    def _serve(self, tool, args, url, payment, grant, refund_url=None, price=None):
        resource = Engine.resource(url, tool["description"])
        if tool.get("meter") == "time" and price is not None:
            bound = self.meter.call_ms(price["micro"])
            run = lambda: self._bounded(bound, tool, args)
        else:
            run = lambda: self._run(tool, args)
        base = re.sub(r"/refund$", "", refund_url or "")
        return self.engine.serve(tool["name"], resource, payment, grant, run, refund_url, price, self._listing(base), self._bought(tool, args))

    @staticmethod
    def _timed_out(s):
        return s.get("failed") and s["status"] == 402 and isinstance(s["body"], dict) and s["body"].get("error") == "out_of_time"

    def _free(self, tool, args, client, line=""):
        if tool.get("builtin") == "line":
            out = self._line_tool(args, line)
            return {"ok": not out.get("isError"), "body": out, "status": 500}
        who = client or ""
        take = self._free_limit.take_saved(self._free_file, tool["name"], who) if self._free_file else self._free_limit.take(tool["name"], who)
        if not take["ok"]:
            return {"limited": FreeLimit.refusal(self.engine.free_limit, take["retryAfter"]), "retryAfter": take["retryAfter"]}
        (ok, out, status), _units = metered(lambda: self._run(tool, args))
        return {"ok": ok, "body": out, "status": status}

    def _on_line(self, tool, args, credential):
        meter = self.meter
        channel_id = meter.line(credential)
        if not channel_id:
            return {"status": 403, "body": {"error": "line_unknown", "message": 'no open line with that credential. Open one: POST <base>/line {"op":"open","channelId"}.'}}
        ch = self.engine.channel(channel_id)
        if not ch:
            return {"status": 403, "body": {"error": "line_unknown", "message": "no channel for this line."}}
        if _int(ch.get("withdrawRequestedAt")) > 0:
            return {"status": 402, "body": {"error": "channel_leaving", "message": "this channel is withdrawing. Open a new channel."}}
        ident = secrets.token_hex(8)
        b = meter.begin(channel_id, ident)
        if not b["ok"]:
            why = 'the meter is off. Send {"op":"on"} to the line.' if b["code"] == "meter_off" else "this line has no time left. Buy time: buy_time."
            return {"status": 402, "body": {"error": b["code"], "message": why, "msRemaining": meter.status(channel_id)["msRemaining"]}}
        out, _units = metered(lambda: self._within(b["deadline"] - meter._now(), tool, args), deadline=b["deadline"], channelId=channel_id)
        stopped = meter._now()
        cut = out is None or (stopped >= b["deadline"] and not out[0])
        e = meter.end(channel_id, ident) if cut else meter.end(channel_id, ident, b["started"], stopped)
        meta = {"channelId": channel_id, "msRemaining": e["msRemaining"], "elapsedMs": e["elapsedMs"]}
        if cut or (e["over"] and (out[0] or isinstance(out[1], dict))):
            return {"status": 402, "body": {**_out_of_time(True), "msRemaining": e["msRemaining"]}}
        ok, value, status = out
        if not ok:
            return {"status": status, "failed": value}
        return {"status": 200, "value": value, "meta": meta}

    def _line_tool(self, args, header_credential):
        l = self._line_op(args, header_credential)
        return l["body"] if l["status"] == 200 else {"isError": True, "structuredContent": l["body"], "content": _text(l["body"])}

    def _line_op(self, args, header_credential=""):
        def fail(status, code, why):
            return {"status": status, "body": {"op": "line_failed", "code": code, "why": why}}

        if not self.time:
            return fail(404, "no_time", "this seller sells no line time")
        if not isinstance(args, dict) or args.get("op") not in LINE_OPS:
            return fail(400, "bad_request", "op is open, prove, on, off, status or close")
        meter = self.meter
        op = args["op"]
        if op in ("open", "prove"):
            cid = args.get("channelId")
            ident = cid.lower() if isinstance(cid, str) and CHANNEL.match(cid) else None
            ch = self.engine.channel(ident) if ident else None
            if not ch or not ch.get("channelConfig"):
                return fail(404, "unknown_channel", "no channel with that id here. Pay a call on it first, e.g. buy_time.")
            if _int(ch.get("withdrawRequestedAt")) > 0:
                return fail(409, "channel_leaving", "this channel is withdrawing. Open a new channel.")
            if op == "open":
                c = meter.challenge(ident)
                return {"status": 200, "body": {"op": "challenge", "channelId": ident, "nonce": c["nonce"], "sign": c["message"], "expiresInSeconds": c["expiresInSeconds"]}}
            r = meter.prove(ch, args.get("nonce"), args.get("signature"))
            if not r["ok"]:
                return fail(r["status"], r["code"], r["why"])
            return {"status": 200, "body": {"op": "opened", "credential": r["credential"], "channelId": ident, "msRemaining": r["msRemaining"], "metering": True}}
        credential = args["credential"] if isinstance(args.get("credential"), str) and args["credential"] != "" else header_credential
        ident = meter.line(credential)
        if not ident:
            return fail(403, "line_unknown", "no open line with that credential")
        if op == "close":
            meter.close(credential)
            return {"status": 200, "body": {"op": "closed", "channelId": ident}}
        if op != "status":
            meter.switch(ident, op == "on")
        return {"status": 200, "body": {"op": op, **meter.status(ident)}}

    def _price_or_refusal(self, tool, args):
        if self.mode == "gate":
            return None, None
        try:
            return self._price_for(tool, args), None
        except Exception as e:
            return None, {"error": "price_invalid", "tool": tool["name"], "message": str(e)}

    @staticmethod
    def _http_failure(tool_name, out, status):
        message = out if isinstance(out, str) else _said(out)
        return status, {}, {"error": "invalid_arguments" if status == 400 else "tool_failed", "message": message, "tool": tool_name}

    def _http(self, tool_name, headers, body, resource=None, refund_url=None, client=""):
        tool = self._tools.get(tool_name)
        if not tool:
            return 404, {}, {"error": "unknown_tool", "tool": tool_name, "tools": list(self._tools)}
        try:
            args = _loads(body or b"{}")
        except ValueError as e:
            return 400, {}, {"error": "invalid_arguments", "message": f"the body is not JSON: {e}", "tool": tool_name}
        wrong = _validate(tool["inputSchema"], args)
        if wrong:
            return 400, {}, {"error": "invalid_arguments", "message": wrong, "tool": tool_name}
        if tool.get("builtin") == "line":
            l = self._line_op(args, _first(headers, ("x-line",)))
            return l["status"], {}, l["body"]
        if self._is_free(tool):
            f = self._free(tool, args, client, _first(headers, ("x-line",)))
            if "limited" in f:
                return 429, {"retry-after": str(f["retryAfter"])}, f["limited"]
            if not f["ok"]:
                return self._http_failure(tool_name, f["body"], f["status"])
            return 200, {}, f["body"]
        credential = _first(headers, ("x-line",))
        if tool.get("meter") == "time" and credential:
            l = self._on_line(tool, args, credential)
            if "failed" in l:
                return self._http_failure(tool_name, l["failed"], l["status"])
            if l["status"] != 200:
                return l["status"], {}, l["body"]
            return 200, {"x-pass-ms-remaining": str(l["meta"]["msRemaining"]), "x-pass-ms-elapsed": str(l["meta"]["elapsedMs"])}, l["value"]
        price, invalid = self._price_or_refusal(tool, args)
        if invalid:
            return 500, {}, invalid
        s = self._serve(tool, args, resource, _first(headers, ("payment-signature", "x-payment")), _first(headers, ("x-grant",)), refund_url, price)
        if self._timed_out(s):
            return 402, {}, s["body"]
        if s.get("failed"):
            return self._http_failure(tool_name, s["body"], s["status"])
        return s["status"], s["headers"], s["body"]

    @staticmethod
    def _payment_result(s):
        body = s["body"] if isinstance(s["body"], dict) else {}
        required = _unb64(s["headers"].get("PAYMENT-REQUIRED", "")) if s["headers"].get("PAYMENT-REQUIRED") else None
        if not isinstance(required, dict):
            required = body if "accepts" in body else None
        if not required and s["status"] == 402:
            required = {"x402Version": 2, "accepts": [], **body}
        if required:
            required = {"x402Version": 2, **body, **required}
            return {"isError": True, "structuredContent": required, "content": _text(required)}
        if isinstance(s["body"], dict):
            return {"isError": True, "structuredContent": s["body"], "content": _text(s["body"])}
        return {"isError": True, "content": _text(body)}

    @staticmethod
    def _mcp_failure(out, status, name):
        if isinstance(out, dict):
            return _failed(out, name)
        return _failure({"error": "invalid_arguments" if status == 400 else "tool_failed", "tool": name, "message": out})

    @staticmethod
    def _delivered(out, meta=None):
        extra = {"_meta": meta} if meta else {}
        structured = {"structuredContent": out} if isinstance(out, dict) else {}
        return {"content": _text(out), **structured, **extra}

    def _mcp_call(self, params, resource=None, grant="", refund_url=None, client="", line=""):
        if not isinstance(params, dict):
            raise _RpcError(-32602, "params must be an object")
        name = params.get("name")
        if not isinstance(name, str):
            raise _RpcError(-32602, "params.name must be a string")
        meta = params.get("_meta", {})
        if meta is None:
            meta = {}
        if not isinstance(meta, dict):
            raise _RpcError(-32602, "params._meta must be an object")
        args = params.get("arguments", {})
        if args is None:
            args = {}
        if not isinstance(args, dict):
            raise _RpcError(-32602, "params.arguments must be an object")
        payment = meta.get(META_PAYMENT)
        if payment is not None and not isinstance(payment, dict):
            raise _RpcError(-32602, 'params._meta["x402/payment"] must be an object')
        tool = self._tools.get(name)
        if not tool:
            return _failure({"error": "unknown_tool", "tool": name, "tools": list(self._tools)})
        wrong = _validate(tool["inputSchema"], args)
        if wrong:
            return _failure({"error": "invalid_arguments", "tool": name, "message": wrong})
        credential = meta.get(META_LINE).strip() if isinstance(meta.get(META_LINE), str) and meta[META_LINE].strip() else (line or "").strip()
        if self._is_free(tool):
            f = self._free(tool, args, client, credential)
            if "limited" in f:
                return _failure(f["limited"])
            if not f["ok"]:
                return self._mcp_failure(f["body"], f["status"], name)
            return self._delivered(f["body"])
        if tool.get("meter") == "time" and credential:
            l = self._on_line(tool, args, credential)
            if "failed" in l:
                return self._mcp_failure(l["failed"], l["status"], name)
            if l["status"] != 200:
                return _failure(l["body"])
            return self._delivered(l["value"], {META_METER: l["meta"]})
        price, invalid = self._price_or_refusal(tool, args)
        if invalid:
            return _failure(invalid)
        s = self._serve(tool, args, resource, _b64(payment) if payment else "", grant, refund_url, price)
        if self._timed_out(s):
            return _failure(s["body"])
        if s.get("failed"):
            return self._mcp_failure(s["body"], s["status"], name)
        if not s["delivered"]:
            return self._payment_result(s)
        return self._delivered(s["body"], _settled_meta(s["headers"]))

    def _mcp_one(self, msg, resource=None, grant="", refund_url=None, client="", line=""):
        if not isinstance(msg, dict) or msg.get("jsonrpc") != "2.0" or not isinstance(msg.get("method"), str):
            return {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "invalid request"}}
        if "id" not in msg:
            return None
        ident = msg["id"]
        try:
            method = msg["method"]
            params = msg.get("params")
            if params is not None and not isinstance(params, (dict, list)):
                raise _RpcError(-32602, "params must be an object")
            if method == "initialize":
                asked = params.get("protocolVersion") if isinstance(params, dict) else None
                result = {"protocolVersion": asked if asked in MCP_VERSIONS else MCP_VERSIONS[0], "capabilities": {"tools": {}},
                          "serverInfo": {"name": self.server_name, "version": self.version}}
            elif method == "ping":
                result = {}
            elif method == "tools/list":
                result = {"tools": self.tools()}
            elif method == "tools/call":
                result = self._mcp_call(params, resource, grant, refund_url, client, line)
            else:
                raise _RpcError(-32601, f"method not found: {method}")
            return {"jsonrpc": "2.0", "id": ident, "result": result}
        except _RpcError as e:
            return {"jsonrpc": "2.0", "id": ident, "error": {"code": e.code, "message": str(e)}}
        except Exception as e:
            return {"jsonrpc": "2.0", "id": ident, "error": {"code": -32603, "message": f"internal error: {e}"}}

    def _mcp(self, body, resource=None, grant="", refund_url=None, client="", line=""):
        try:
            data = _loads(body or b"")
        except ValueError:
            return 400, {}, {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "parse error"}}
        batch = isinstance(data, list)
        if batch and not data:
            return 400, {}, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "invalid request: empty batch"}}
        answers = [a for a in (self._mcp_one(m, resource, grant, refund_url, client, line) for m in (data if batch else [data])) if a is not None]
        if not answers:
            return 202, {}, None
        return 200, {}, answers if batch else answers[0]

    def _responses(self, tool):
        if self._is_free(tool):
            out = {"200": {"description": "the result"}, "400": {"description": "invalid arguments"}}
            if self.engine.free_limit:
                out["429"] = {"description": f"over {self.engine.free_limit} free calls an hour per address"}
            return out
        return {"200": {"description": "the result"}, "400": {"description": "invalid arguments; nothing is charged"},
                "402": {"description": "x402 payment required"}, "403": {"description": "key not admitted"}}

    def openapi(self, base_url):
        paths = {f"/v1/{t['name']}": {"post": {"operationId": t["name"], "summary": t["description"], "x-price": self._price_tag(t),
                                                "requestBody": {"required": True, "content": {"application/json": {"schema": t["inputSchema"]}}},
                                                "responses": self._responses(t)}}
                 for t in self._tools.values()}
        info = {"title": self.server_name, "version": self.version,
                "description": f"{HOW[self.mode]}. MCP: {base_url}/mcp"}
        contact = self.engine.contact
        if contact:
            info["contact"] = {"email": contact[7:]} if contact.lower().startswith("mailto:") else {"url": contact}
        return {"openapi": "3.0.3", "info": info, "servers": [{"url": base_url}], "paths": paths}

    def refund(self, body):
        try:
            args = json.loads(body or b"{}")
        except ValueError:
            return 400, {}, {"op": "refund_failed", "code": "invalid_json", "why": "the body is not JSON"}
        if not isinstance(args, dict):
            return 400, {}, {"op": "refund_failed", "code": "invalid_request", "why": "expected { channelId, issued, signature }, optional selfSend or gasPayment"}
        try:
            r = self.engine.refund(args)
        except Exception as e:
            return 503, {}, {"op": "refund_failed", "code": "refund_unavailable", "why": f"the refund could not be handled right now: {e}"}
        return r["status"], {}, r["body"]

    @staticmethod
    def ours(path):
        return path in ("/mcp", "/openapi.json", "/refund", "/line") or path.startswith("/v1/")

    def handle(self, method, path, headers, body, base_url="", client=""):
        out = self._handle(method, path, {k.lower(): v for k, v in headers.items()}, body, base_url, client or "")
        if out is None:
            return None
        status, extra, payload = out
        return status, {**CORS, **extra}, payload

    def _handle(self, method, path, headers, body, base_url, client=""):
        if method == "OPTIONS" and self.ours(path):
            return 204, dict(PREFLIGHT), None
        refund_at = self._public(base_url + "/refund")
        mcp = self._public(base_url + "/mcp")
        if path == "/mcp":
            if method != "POST":
                return 405, {"allow": "POST, OPTIONS"}, None
            return self._mcp(body, mcp, _first(headers, ("x-grant",)), refund_at, client, _first(headers, ("x-line",)))
        if path == "/openapi.json" and method == "GET":
            return 200, {}, self.openapi(base_url)
        if path == "/refund" and method == "POST":
            return self.refund(body)
        if path == "/line" and method == "POST" and self.time:
            try:
                args = _loads(body or b"")
            except ValueError:
                return 400, {}, {"op": "line_failed", "code": "bad_request", "why": "the body is not JSON"}
            l = self._line_op(args, _first(headers, ("x-line",)))
            return l["status"], {}, l["body"]
        if path.startswith("/v1/") and method == "POST":
            return self._http(path[4:], headers, body, self._public(base_url + path), refund_at, client)
        return None

    def asgi(self, fallback=None):
        async def app(scope, receive, send):
            if scope["type"] != "http":
                if fallback:
                    return await fallback(scope, receive, send)
                return
            path = scope["path"][len(scope.get("root_path", "")):] or "/"
            if not self.ours(path) and fallback:
                return await fallback(scope, receive, send)
            headers = {k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope.get("headers", [])}
            try:
                declared = int(headers.get("content-length") or 0)
            except ValueError:
                declared = 0
            chunks, size, big = [], 0, declared > MAX_BODY
            while not big:
                event = await receive()
                if event.get("type") == "http.disconnect":
                    return
                chunk = event.get("body", b"")
                size += len(chunk)
                if size > MAX_BODY:
                    big = True
                    break
                chunks.append(chunk)
                if not event.get("more_body"):
                    break
            body = b"".join(chunks)
            if big:
                out = (413, dict(CORS), {"error": "body_too_large", "limit": MAX_BODY})
            else:
                host = headers.get("host", "localhost")
                base_url = f"{scope.get('scheme', 'http')}://{host}{scope.get('root_path', '')}"
                peer = scope.get("client")
                client = str(peer[0]) if isinstance(peer, (list, tuple)) and peer and peer[0] is not None else ""
                out = await asyncio.to_thread(self.handle, scope["method"], path, headers, body, base_url, client)
            if out is None:
                if fallback:
                    replayed = [{"type": "http.request", "body": body, "more_body": False}]

                    async def again():
                        return replayed.pop() if replayed else await receive()
                    return await fallback(scope, again, send)
                out = (404, {}, {"error": "not here"})
            status, extra, payload = out
            raw = b"" if payload is None else json.dumps(payload).encode()
            hdrs = [(b"content-type", b"application/json")] + [(k.lower().encode(), str(v).encode()) for k, v in extra.items()]
            await send({"type": "http.response.start", "status": status, "headers": hdrs})
            await send({"type": "http.response.body", "body": raw})
        return app

    def wsgi(self, fallback=None):
        def app(environ, start_response):
            path = environ.get("PATH_INFO") or "/"
            try:
                path = path.encode("latin-1").decode("utf-8")
            except (UnicodeEncodeError, UnicodeDecodeError):
                pass
            if not self.ours(path) and fallback:
                return fallback(environ, start_response)
            try:
                length = max(int(environ.get("CONTENT_LENGTH") or 0), 0)
            except ValueError:
                length = 0
            if length > MAX_BODY:
                out = (413, dict(CORS), {"error": "body_too_large", "limit": MAX_BODY})
                body = b""
            else:
                body = environ["wsgi.input"].read(length) if length else b""
                headers = {k[5:].replace("_", "-").lower(): v for k, v in environ.items() if k.startswith("HTTP_")}
                if environ.get("CONTENT_TYPE"):
                    headers["content-type"] = environ["CONTENT_TYPE"]
                base_url = f"{environ.get('wsgi.url_scheme', 'http')}://{environ.get('HTTP_HOST', 'localhost')}{environ.get('SCRIPT_NAME', '')}"
                out = self.handle(environ["REQUEST_METHOD"], path, headers, body, base_url, environ.get("REMOTE_ADDR") or "")
            if out is None:
                if fallback:
                    environ["wsgi.input"] = io.BytesIO(body)
                    environ["CONTENT_LENGTH"] = str(len(body))
                    return fallback(environ, start_response)
                out = (404, {}, {"error": "not here"})
            status, extra, payload = out
            raw = b"" if payload is None else json.dumps(payload).encode()
            start_response(f"{status} {_reason(status)}", [("content-type", "application/json")] + [(k, str(v)) for k, v in extra.items()])
            return [raw]
        return app

    def paid(self, fn):
        priced = {"name": fn.__name__}
        if self.mode != "gate" and not callable(self.engine.prices):
            self._price_for(priced)

        @functools.wraps(fn)
        def wrapper(*args, **kwargs):
            from flask import request, make_response, jsonify
            box = {}
            price, invalid = self._price_or_refusal(priced, dict(kwargs))
            if invalid:
                return make_response(jsonify(invalid), 500)

            def run():
                box["resp"] = make_response(fn(*args, **kwargs))
                return (box["resp"].status_code < 400), box["resp"], box["resp"].status_code

            headers = {k.lower(): v for k, v in request.headers.items()}
            resource = Engine.resource(self._public(request.url), (fn.__doc__ or "").strip())
            s = self.engine.serve(fn.__name__, resource, _first(headers, ("payment-signature", "x-payment")), _first(headers, ("x-grant",)), run, None, price, self._listing())
            if s.get("failed"):
                return s["body"]
            if not s["delivered"]:
                return self._flask(make_response(jsonify(s["body"]), s["status"]), s["headers"])
            return self._flask(s["body"], s["headers"])
        return wrapper

    def refund_view(self):
        def refund():
            from flask import request, make_response, jsonify
            status, _, body = self.refund(request.get_data())
            return make_response(jsonify(body), status)
        return refund

    @staticmethod
    def _flask(resp, headers):
        for k, v in headers.items():
            resp.headers[k] = v
        return resp
