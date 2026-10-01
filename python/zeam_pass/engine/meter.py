import base64
import copy
import hashlib
import os
import secrets
import time

from .core import Secp256k1
from .pricing import micro_of, usd
from .settlement.store import FileStore

NONCE_SECS = 300
KEEP_CREDITED = 64
KEEP_NONCES = 8
KEEP_LINES = 8


class Clock:

    @staticmethod
    def fresh(channel_id):
        return {"channelId": str(channel_id).lower(), "balanceMs": 0, "spentMs": 0, "returnedMs": 0, "on": False, "since": None,
                "lastActive": None, "calls": {}, "credited": [], "nonces": {}, "lines": []}

    @staticmethod
    def covered(intervals):
        spans = sorted(((s, e) for s, e in intervals if e > s), key=lambda x: (x[0], x[1]))
        total = 0
        end = None
        for s, e in spans:
            if end is None or s >= end:
                total += e - s
                end = e
            elif e > end:
                total += e - end
                end = e
        return total

    @staticmethod
    def _drop_ended(r, now):
        for ident in [i for i, c in r["calls"].items() if c["deadline"] <= now]:
            del r["calls"][ident]

    @staticmethod
    def settle(rec, now, idle_ms=0):
        r = rec
        if r["since"] is None:
            r["since"] = now
            Clock._drop_ended(r, now)
            return r
        since = r["since"]
        spans = [(max(c["start"], since), min(now, c["deadline"])) for c in r["calls"].values()]
        if r["on"] and idle_ms > 0 and r["lastActive"] is not None:
            spans.append((max(r["lastActive"], since), min(now, r["lastActive"] + idle_ms)))
        burned = min(r["balanceMs"], Clock.covered(spans))
        r["balanceMs"] -= burned
        r["spentMs"] += burned
        r["since"] = now
        Clock._drop_ended(r, now)
        return r

    @staticmethod
    def remaining(rec, now, idle_ms=0):
        return Clock.settle(copy.deepcopy(rec), now, idle_ms)["balanceMs"]

    @staticmethod
    def switch(rec, on, now, idle_ms=0):
        Clock.settle(rec, now, idle_ms)
        rec["on"] = on
        rec["since"] = now
        if on:
            rec["lastActive"] = now
        return rec

    @staticmethod
    def begin(rec, ident, now, idle_ms=0):
        Clock.settle(rec, now, idle_ms)
        if not rec["on"]:
            return {"ok": False, "code": "meter_off"}
        if rec["balanceMs"] <= 0:
            return {"ok": False, "code": "out_of_time"}
        deadline = now + rec["balanceMs"]
        rec["calls"][ident] = {"start": now, "deadline": deadline}
        rec["lastActive"] = now
        return {"ok": True, "deadline": deadline}

    @staticmethod
    def end(rec, ident, now, idle_ms=0, start=None, stop=None):
        c = rec["calls"].get(ident)
        ran = min(now if stop is None else stop, now)
        at = ran if rec["since"] is None else max(ran, rec["since"])
        if c and start is not None and start > c["start"] and ran <= c["deadline"]:
            c["start"] = min(start, ran)
        Clock.settle(rec, at, idle_ms)
        rec["calls"].pop(ident, None)
        rec["lastActive"] = ran if rec["lastActive"] is None else max(rec["lastActive"], ran)
        return {"elapsedMs": ran - c["start"] if c else 0, "over": c is None or ran > c["deadline"]}

    @staticmethod
    def buy(rec, ms, key, now, idle_ms=0):
        if key in rec["credited"]:
            return False
        Clock.settle(rec, now, idle_ms)
        rec["balanceMs"] += ms
        rec["credited"] = (rec["credited"] + [key])[-KEEP_CREDITED:]
        return True

    @staticmethod
    def unbuy(rec, ms, key, now, idle_ms=0):
        if key not in rec["credited"]:
            return 0
        Clock.settle(rec, now, idle_ms)
        taken = min(ms, rec["balanceMs"])
        rec["balanceMs"] -= taken
        rec["credited"] = [k for k in rec["credited"] if k != key]
        return taken

    @staticmethod
    def unburned_micro(rec, now, idle_ms, block_micro, block_ms):
        left = Clock.remaining(rec, now, idle_ms)
        return (left * block_micro + block_ms - 1) // block_ms

    @staticmethod
    def forget(rec, now, idle_ms=0):
        Clock.settle(rec, now, idle_ms)
        returned = rec["balanceMs"]
        rec["returnedMs"] += returned
        rec["balanceMs"] = 0
        rec["on"] = False
        rec["calls"] = {}
        return returned

    @staticmethod
    def line_message(channel_id, nonce):
        return f"ZEAM Pass line\nchannel: {str(channel_id).lower()}\nnonce: {nonce}"


def _whole(value):
    if isinstance(value, bool):
        return int(value)
    try:
        return int(float(value))
    except (TypeError, ValueError, OverflowError):
        return None


def time_options(o):
    if o is None or o is False:
        return None
    if not isinstance(o, dict):
        raise ValueError('Pass: time is {"block": "0.00025", "blockMs": 250}')
    block_micro = micro_of(o.get("block"), "time.block")
    block_ms = 250 if o.get("blockMs") is None else _whole(o["blockMs"])
    idle_ms = 0 if o.get("idleMs") is None else _whole(o["idleMs"])
    max_blocks = min(14400, 1000000000 // block_micro) if o.get("maxBlocks") is None else _whole(o["maxBlocks"])
    if block_ms is None or not 1 <= block_ms <= 3600000:
        raise ValueError("Pass: time.blockMs is 1 to 3600000")
    if idle_ms is None or not 0 <= idle_ms <= 3600000:
        raise ValueError("Pass: time.idleMs is 0 to 3600000")
    if max_blocks is None or not (max_blocks >= 1 and max_blocks * block_micro <= 1000000000):
        raise ValueError("Pass: time.maxBlocks is 1 or more, and at most $1,000 of blocks")
    return {"blockMicro": block_micro, "blockMs": block_ms, "idleMs": idle_ms, "maxBlocks": max_blocks}


def time_text(t, base=""):
    idle = f" and {t['idleMs']} ms after each" if t["idleMs"] > 0 else ""
    return (f"Line time: ${usd(t['blockMicro'])} per {t['blockMs']} ms block. buy_time {{blocks}} (1 to {t['maxBlocks']}); "
            "the time is credited to the paying channel once the payment settles. "
            f'Open a line: POST {base}/line {{"op":"open","channelId"}}, sign the message it returns with the payer key, '
            'POST {"op":"prove","channelId","nonce","signature"}. Send the credential as x-line (MCP: _meta["zeam-pass/line"]). '
            f'Time burns while a call runs on the line{idle}; a call stops when the time runs out. {{"op":"off"}}: no new calls on the line; a running call burns to its end. '
            "Unburned time comes back with a refund.")


class TimeMeter:

    def __init__(self, directory, t, now=None, clocks=None, lines=None):
        self.t = t
        self.now = now if callable(now) else (lambda: int(time.time() * 1000))
        self.clocks = clocks if clocks is not None else FileStore(os.path.join(directory, "clocks"))
        self.lines = lines if lines is not None else FileStore(os.path.join(directory, "lines"))

    @staticmethod
    def hash(credential):
        return "0x" + hashlib.sha256(str(credential).encode("utf-8")).hexdigest()

    def ms_of(self, blocks):
        return blocks * self.t["blockMs"]

    def call_ms(self, price_micro):
        return (price_micro * self.t["blockMs"]) // self.t["blockMicro"]

    def _now(self):
        return int(self.now())

    def change(self, channel_id, fn):
        box = {}

        def apply(current):
            rec = current if current is not None else Clock.fresh(channel_id)
            box["out"] = fn(rec)
            return rec

        self.clocks.update(str(channel_id).lower(), apply)
        return box.get("out")

    def status(self, channel_id):
        rec = self.clocks.get(channel_id) or Clock.fresh(channel_id)
        now = self._now()
        idle = self.t["idleMs"]
        return {"channelId": rec["channelId"], "msRemaining": Clock.remaining(rec, now, idle),
                "msSpent": Clock.settle(copy.deepcopy(rec), now, idle)["spentMs"], "msReturned": rec["returnedMs"],
                "metering": rec["on"], "blockMs": self.t["blockMs"], "blockUSD": usd(self.t["blockMicro"])}

    def credit(self, channel_id, ms, key):
        return self.change(channel_id, lambda rec: Clock.buy(rec, ms, str(key), self._now(), self.t["idleMs"]))

    def uncredit(self, channel_id, ms, key):
        return self.change(channel_id, lambda rec: Clock.unbuy(rec, ms, str(key), self._now(), self.t["idleMs"]))

    def switch(self, channel_id, on):
        def flip(rec):
            Clock.switch(rec, on, self._now(), self.t["idleMs"])
            return rec["balanceMs"]
        return self.change(channel_id, flip)

    def begin(self, channel_id, ident):
        b = self.change(channel_id, lambda rec: Clock.begin(rec, ident, self._now(), self.t["idleMs"]))
        return {**b, "started": self._now()} if b["ok"] else b

    def end(self, channel_id, ident, started=None, stopped=None):
        def stop(rec):
            out = Clock.end(rec, ident, self._now(), self.t["idleMs"], started, stopped)
            return {**out, "msRemaining": rec["balanceMs"]}
        return self.change(channel_id, stop)

    def stop(self, channel_id):
        def halt(rec):
            Clock.settle(rec, self._now(), self.t["idleMs"])
            if rec["calls"]:
                return False
            Clock.switch(rec, False, self._now(), self.t["idleMs"])
            return True
        return self.change(channel_id, halt)

    def busy(self, channel_id):
        rec = self.clocks.get(channel_id)
        now = self._now()
        return rec is not None and any(c["deadline"] > now for c in (rec.get("calls") or {}).values())

    def unburned_micro(self, channel_id):
        rec = self.clocks.get(channel_id)
        if rec is None:
            return 0
        return Clock.unburned_micro(rec, self._now(), self.t["idleMs"], self.t["blockMicro"], self.t["blockMs"])

    def _drop(self, h):
        try:
            self.lines.update(h, lambda _c: None)
        except Exception:
            pass

    def forget(self, channel_id):
        box = {"lines": []}

        def wipe(rec):
            box["lines"] = rec["lines"]
            rec["lines"] = []
            return Clock.forget(rec, self._now(), self.t["idleMs"])

        returned = self.change(channel_id, wipe)
        for h in box["lines"]:
            self._drop(h)
        return returned

    def challenge(self, channel_id):
        nonce = secrets.token_hex(16)
        now = self._now()

        def ask(rec):
            live = [(k, until) for k, until in rec["nonces"].items() if until > now][-(KEEP_NONCES - 1):]
            rec["nonces"] = dict(live + [(nonce, now + NONCE_SECS * 1000)])
            return {"nonce": nonce, "message": Clock.line_message(channel_id, nonce), "expiresInSeconds": NONCE_SECS}

        return self.change(channel_id, ask)

    def prove(self, channel, nonce, signature):
        channel_id = str(channel["channelId"]).lower()
        now = self._now()

        def take(rec):
            until = rec["nonces"].pop(str(nonce), None)
            return until is not None and until > now

        if not self.change(channel_id, take):
            return {"ok": False, "status": 400, "code": "no_challenge", "why": 'no live challenge with that nonce. Send {"op":"open"} again.'}
        try:
            who = Secp256k1.recover_personal(Clock.line_message(channel_id, nonce), str(signature if signature is not None else "")).lower()
        except Exception as e:
            return {"ok": False, "status": 400, "code": "bad_signature", "why": f"the signature does not recover: {str(e)[:80]}"}
        config = channel.get("channelConfig") if isinstance(channel.get("channelConfig"), dict) else {}
        payer = str(config.get("payer") or "").lower()
        auth = str(config.get("payerAuthorizer") or "").lower()
        if who != payer and who != auth:
            return {"ok": False, "status": 403, "code": "not_the_payer", "why": f"{who} is not this channel's payer"}
        credential = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode().rstrip("=")
        h = TimeMeter.hash(credential)
        self.lines.update(h, lambda _c: {"channelId": channel_id, "at": now})
        box = {"dropped": []}

        def keep(rec):
            every = rec["lines"] + [h]
            box["dropped"] = every[:-KEEP_LINES]
            rec["lines"] = every[-KEEP_LINES:]
            Clock.switch(rec, True, now, self.t["idleMs"])
            return rec["balanceMs"]

        ms_remaining = self.change(channel_id, keep)
        for x in box["dropped"]:
            self._drop(x)
        return {"ok": True, "credential": credential, "channelId": channel_id, "msRemaining": ms_remaining}

    def line(self, credential):
        if not isinstance(credential, str) or credential == "":
            return None
        hit = self.lines.get(TimeMeter.hash(credential))
        return hit.get("channelId") if isinstance(hit, dict) else None

    def close(self, credential):
        h = TimeMeter.hash(credential)
        hit = self.lines.get(h)
        if not hit:
            return None
        self.lines.update(h, lambda _c: None)

        def unlink(rec):
            rec["lines"] = [x for x in rec["lines"] if x != h]

        self.change(hit["channelId"], unlink)
        return hit["channelId"]
