import json
import os
import re
import signal
import sys
import time
from socketserver import ThreadingMixIn
from wsgiref.simple_server import WSGIRequestHandler, WSGIServer, make_server

from zeam_pass import Pass, units
from zeam_pass.engine import DEFAULT_CREDITS, DEFAULT_RELAY, DEFAULT_RPC

env = os.environ
metered = env.get("PASS_METERED") == "1"
prices = dict(kv.split("=", 1) for kv in re.split(r"[\s,]+", env.get("PASS_PRICES", "")) if "=" in kv)
agents = Pass(name=env["PASS_NAME"], payout=env["PASS_PAYOUT"], mode=env.get("PASS_MODE", "both"), price=env.get("PASS_PRICE", "0.01"),
              admit=[a for a in re.split(r"[\s,]+", env.get("PASS_ADMIT", "")) if a],
              relay=env.get("PASS_RELAY") or DEFAULT_RELAY, credits=env.get("PASS_CREDITS") or DEFAULT_CREDITS,
              rpc=env.get("PASS_RPC") or DEFAULT_RPC, site=env.get("PASS_SITE") or None,
              state_dir=env.get("PASS_STATE_DIR"), contact=env.get("PASS_CONTACT") or None, server_name="ZEAM Pass reference seller (Python)",
              prices=prices or None, free_limit=int(env["PASS_FREE_LIMIT"]) if env.get("PASS_FREE_LIMIT") else None,
              time={"block": env["PASS_TIME_BLOCK"], "blockMs": int(env.get("PASS_TIME_BLOCK_MS") or 250)} if metered and env.get("PASS_TIME_BLOCK") else None)


@agents.tool(description="Multiplies two numbers.", input_schema={"type": "object", "properties": {"a": {"type": "number"}, "b": {"type": "number"}}, "required": ["a", "b"]})
def multiply(a, b):
    return {"product": a * b}


@agents.tool(description="Counts the words in a text.", input_schema={"type": "object", "properties": {"text": {"type": "string", "maxLength": 10000}}, "required": ["text"]})
def word_count(text):
    return {"words": len(text.split())}


if metered:
    @agents.tool(name="ping", free=True, description="Free. Answers pong and the time.")
    def ping(**_):
        return {"pong": True, "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}

    @agents.tool(name="words", price="0.05", unit="0.0001", description="Counts the words in a text: $0.0001 per word, at most $0.05 a call.",
                 input_schema={"type": "object", "properties": {"text": {"type": "string", "maxLength": 20000}}, "required": ["text"]})
    def words(text):
        n = len(text.split())
        units(n)
        return {"words": n}

    if env.get("PASS_TIME_BLOCK"):
        @agents.tool(name="wait", meter="time", description="Waits ms milliseconds, then answers. Line time.",
                     input_schema={"type": "object", "properties": {"ms": {"type": "integer", "minimum": 0, "maximum": 9000}}, "required": ["ms"]})
        def wait(ms):
            time.sleep(ms / 1000)
            return {"waited": ms}


paid = agents.wsgi()
gated = None
if env.get("PASS_GATE_NAME"):
    gated = Pass(name=env["PASS_GATE_NAME"], payout=env["PASS_PAYOUT"], mode="gate",
                 admit=[a for a in re.split(r"[\s,]+", env.get("PASS_ADMIT", "")) if a],
                 credits=env.get("PASS_CREDITS") or DEFAULT_CREDITS, rpc=env.get("PASS_RPC") or DEFAULT_RPC, site=env.get("PASS_SITE") or None,
                 state_dir=os.path.join(env["PASS_STATE_DIR"], "gate") if env.get("PASS_STATE_DIR") else None,
                 contact=env.get("PASS_CONTACT") or None, server_name="ZEAM Pass reference gate (Python)")

    @gated.tool(name="multiply", description="Multiplies two numbers.", input_schema={"type": "object", "properties": {"a": {"type": "number"}, "b": {"type": "number"}}, "required": ["a", "b"]})
    def gated_multiply(a, b):
        return {"product": a * b}

    gate = gated.wsgi()


def app(environ, start_response):
    path = environ.get("PATH_INFO", "/")
    if path == "/health" and environ.get("REQUEST_METHOD") == "GET":
        start_response("200 OK", [("content-type", "application/json")])
        return [json.dumps({"ok": True, "name": env["PASS_NAME"], "mode": env.get("PASS_MODE", "both"), "engine": "python", "gate": env.get("PASS_GATE_NAME") or None}).encode()]
    if path == "/agents" or path.startswith("/agents/"):
        environ["SCRIPT_NAME"], environ["PATH_INFO"] = "/agents", path[len("/agents"):] or "/"
        return paid(environ, start_response)
    if gated is not None and (path == "/gate" or path.startswith("/gate/")):
        environ["SCRIPT_NAME"], environ["PATH_INFO"] = "/gate", path[len("/gate"):] or "/"
        return gate(environ, start_response)
    start_response("404 Not Found", [("content-type", "text/plain")])
    return [b"ZEAM Pass reference seller: the tools are at /agents/mcp and /agents/v1/<tool>\n"]


class Quiet(WSGIRequestHandler):
    def log_message(self, *a):
        pass


class Threaded(ThreadingMixIn, WSGIServer):
    daemon_threads = True


server = make_server(env.get("HOST", "0.0.0.0"), int(env.get("PORT", "8080")), app, server_class=Threaded, handler_class=Quiet)
signal.signal(signal.SIGTERM, lambda *a: sys.exit(0))
print(f"{env['PASS_NAME']} on {env.get('HOST', '0.0.0.0')}:{env.get('PORT', '8080')}", flush=True)
try:
    server.serve_forever()
except (KeyboardInterrupt, SystemExit):
    sys.exit(0)
