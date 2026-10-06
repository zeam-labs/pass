# zeam-pass (Python)

ZEAM :: Pass for your own server. Define your tools once; agents pay per call over MCP and x402 HTTP on your domain.
The Pass engine runs in your process: keys, channel records, gate usage and settlement stay on your server. ZEAM runs
the relay (gas for transactions that cover their own gas) and the credit service (gate checks). You keep 90.01% of
every sale, paid to your own split; the relay pays the gas.

Source: https://github.com/zeam-labs/pass

## Install

Published: `pip install zeam-pass` (Python ≥3.9).
Dependencies: `coincurve` (libsecp256k1, signing) and `pycryptodome` (Keccak, key sealing).

## Start

```python
from zeam_pass import Pass

agents = Pass(name="acme", payout="0xYourWalletAddress", price="0.01")

@agents.tool(
    description="A price for a symbol.",
    input_schema={
        "type": "object",
        "properties": {"symbol": {"type": "string"}},
        "required": ["symbol"],
    },
)
def quote(symbol):
    return {"symbol": symbol, "price": lookup(symbol)}
```

FastAPI / Starlette:

```python
app.mount("/agents", agents.asgi())
```

Flask (werkzeug):

```python
app.wsgi_app = DispatcherMiddleware(app.wsgi_app, {"/agents": agents.wsgi()})
```

Routes: `/agents/mcp` (MCP; payment in the tool call's `_meta`), `POST /agents/v1/<tool>` (x402 headers),
`/agents/openapi.json` and `/agents/refund`, with CORS. Django mounts `agents.wsgi()` or `agents.asgi()` the same
way. `examples/server.py` is a whole server on the standard library.

The agent side: https://zeampass.com/docs/agents

## Modes

What an agent does in each `mode`:

- `paywall` (default): pays `price` USD per call with x402 `batch-settlement`
  (USDC on Base).
- `gate`: signs each call with its key as a zero-value x402 `exact` payment:
  nothing is paid, the key needs no funds; `admit=[...]` lists the admitted
  key addresses; a grant (`x-grant`) from one of them admits one other key.
- `both`: its key must be admitted; it pays per call.

`name` (2 to 32 of a-z, 0-9 and -, starting with a letter) and `payout` (the wallet address earnings go to) fix your
split's address: keep both once you have sold.

Other keyword arguments to `Pass(...)`:

- `price`: USD per call, up to 6 decimals (`"0.02"`); the default for tools
  with no price of their own.
- `prices`: per tool: `{"tool": "0.05"}` or
  `{"tool": {"price": "0.05", "unit": "0.0001"}}`, or a callable
  `(tool, args) -> spec`; a paywall needs `price` or `prices`.
- `free` (default `[]`): tool names served with no payment and no admission.
- `time` (default none): line time:
  `{"usd": "0.00025", "ms": 250, "idleMs": 0}`: that many dollars buys that many milliseconds. A buyer buys any
  number of milliseconds and buying again adds time (`"maxMs": N` limits one purchase, if you want that);
  `paywall` and `both` only; adds `buy_time`, `line` and `POST <base>/line`.
- `free_limit` (default none): `N` or `{"perHour": N}`: N free calls per tool
  per client address per clock hour, kept in `state_dir/free.json` (a restart
  keeps the hour); no client address: one shared count; over it: 429
  `free_limit`, `retry-after`.
- `site` (default the request's origin): your public origin, used in the
  402's resource and refund URL.
- `admit` (default `[]`): admitted key addresses.
- `on_empty` (default `"refuse"`): gate credit at 0: `"refuse"`, or `"allow"`
  and warn.
- `contact` (default none): an `https://` URL or a `mailto:` address; goes in
  every 402, in the 403 `refused` answer (`how` and `contact`) and in
  `openapi.json` `info.contact`.
- `state_dir` (default `$PASS_STATE_DIR` or `~/.zeam-pass/<name>`): keys,
  channel records, gate usage.
- `rpc` (default `https://mainnet.base.org`): a Base RPC URL.
- `relay` (default `https://api.zeampass.com/relay`): ZEAM's relay.
- `credits` (default `https://api.zeampass.com/credits`): ZEAM's gate credit
  service.
- `refund_url`: the refund URL the 402 names.
- `tick_seconds` (default `60`): seconds between background runs; `0` turns
  the thread off.
- `server_name`, `version`: the MCP handshake's server info.
- `fee_recipient`, `credit_issuer` (default `$PASS_FEE_RECIPIENT`,
  `$PASS_CREDIT_ISSUER`): development only.
- `payout_is_fee_recipient` (default `False`): `True` only when `payout` is
  the fee address: the split pays it 100%.
- `settings`: engine tuning; `gateFreePerMonth` and `gateCreditBlock` set the
  gate's free checks and credit block, for tests.

The Python plugin does not read `pass.json`.

`@agents.tool(...)` keyword arguments:

- `name`, `description`, `input_schema` (default the function's name and
  docstring, `{"type": "object"}`).
- `output_schema` (default none): the JSON Schema of the result, for the x402
  `bazaar` discovery extension the 402 carries (`extensions.bazaar`: how to
  call the tool, `input_schema`, `output_schema` or "any JSON"). In the
  `PAYMENT-REQUIRED` header while the extension is at most 4,096 bytes and the
  header at most 12,288; past that, in the body only.
- `price` (default `prices[name]`, then `price`): USD per call; an invalid
  price raises at registration.
- `unit` (default none): USD per unit: the call reserves `price`; the tool
  calls `zeam_pass.units(n)`; charge = min(`price`, n × `unit`).
- `free` (default `False`): `True`: no payment, no admission; arguments still
  validated.
- `meter` (default none): `"time"`: on a line (`x-line`) the call burns the
  line's time; without one it is a paid call bounded to
  the milliseconds its price buys at the `time` rate (past it: 402 `out_of_time`, not
  charged); needs `time`, never free.

Order: the tool's `price`/`unit`, then `prices`, then `price`. The 402 carries this call's amount in `accepts`,
`pricing` for this call and `prices` for every tool; `tools/list` entries carry `_meta["zeam-pass/price"]`; `openapi.json`
operations carry `x-price`. A price the callable cannot give: 500 `price_invalid`. `gate` mode never prices.

## The gate

```python
import os

from zeam_pass import Pass, sign_grant

agents = Pass(
    name="acme",
    payout="0xYourWalletAddress",
    mode="gate",
    admit=["0xAgentKeyAddress"],
)

grant = sign_grant(
    os.environ["ADMITTED_KEY"],
    "0xTheirKeyAddress",
    "2026-12-31T00:00:00Z",
    scope="quote",
)
```

An agent signs each call with its own key; nothing is paid, nothing goes on chain. `sign_grant(key, delegate, until,
scope="*")`: `key` is the private key of an address in `admit`, `delegate` the address of the key you admit, `until`
an ISO time or a timezone-aware `datetime`, `scope` one tool name or `"*"`. It returns the `x-grant` header value for
the agent that holds `delegate`. A call signed by any other key is refused `bad_grant`; so is one past `until`.
Removing the signing key from `admit` ends every grant it signed.

Agent side, in Python: `new_key()` makes a key; `key_address(key)` is the address the seller lists;
`gate_proof(key, payment_required)` signs the zero-value row of a gate's 402 (the body, or its `PAYMENT-REQUIRED`
header) and returns the `PAYMENT-SIGNATURE` value. Pass a gate: https://zeampass.com/docs/agents#pass-a-gate

## Keys

On first start the plugin writes its keys to `state_dir`: the settle key (signs your claims and refunds) and, for
`gate` and `both`, a credit wallet, which holds the gate's USDC; `agents.status()` shows its address. Gate mode: send
it USDC on Base; it needs no ETH. Credit is 2,000 checks at a time for $1 USDC ($0.50 per 1,000), bought after the
month's 10,000 free checks are used and again below 200 remaining. Keep $1 USDC on Base in the credit wallet. `both`
mode needs no USDC in the credit wallet: paid calls are not gate checks.

`keys.json` has mode 0600 in a 0700 directory. `PASS_KEY_SECRET` seals the keys with AES-256-GCM (key from scrypt);
unsealed keys are sealed on the next start. Secret missing or wrong: paid calls answer 503 and nothing is served.

Put `state_dir` on storage that survives a redeploy, and back it up: it holds the keys and the channel records your
earnings are claimed from. `agents.status()` shows the settle key and credit wallet addresses, unclaimed and unpaid
earnings, and gate usage. Fields: https://zeampass.com/docs/sell#status

## A call

1. Arguments are checked against your `input_schema` (types, `required`, `enum`, `minimum`, `maximum`, `minLength`,
   `maxLength`, and the same for an object argument's properties, 1 level deep). Bad arguments, and bodies with
   `NaN`, `Infinity` or numbers too large for a float: 400 (MCP: an error result); nothing held. An unpaid call to
   a paid tool with no arguments (no body, `{}`) gets the 402 with the price. Properties the
   schema does not name are allowed, as in Node: your function gets the ones its signature takes (all of them with
   `**kwargs`).
2. The payment is verified and held.
3. Your tool runs. With a `unit`, it reports whole units with `zeam_pass.units(n)` (outside a tool run: `RuntimeError`).
4. The payment settles only on success. With a `unit`: no report, or a bad one (negative, not whole, bool, str),
   is a failure: released, nothing charged. The settle response's `extra` carries `chargedAmount` and
   `reservedAmount`. The tool raised, returned a dict with `"isError": True`, or returned a value
   that is not valid JSON (a set, a non-finite float, a non-string dict key): the hold is released, nothing is
   charged; the last answers 500 `tool_failed` with the message every engine gives: "the result is not valid JSON (a
   non-finite number or a non-JSON value); nothing was charged".

The payment does not settle: the result is not delivered. Chain unreadable: nothing is served. Request bodies are
capped at 1 MB.

A voucher settles in your process with no transaction. A deposit (the first call on a channel, and every top-up) goes
through the relay and waits for its receipt: that call takes a few seconds.

Behind a proxy or on localhost, pass `site="https://your.domain"`: the 402's resource and refund URL use that origin.

Flask routes you already have: `@agents.paid` holds, runs and settles the same way; an exception or a status of 400
or more releases the hold. Add the refund route:

```python
@app.post("/quote")
@agents.paid
def quote():
    ...

app.add_url_rule("/refund", view_func=agents.refund_view(), methods=["POST"])
```

## Line time

With `time` set, an agent buys time and spends it on a line with no payment per call:

1. `buy_time {"ms": n}` (any number of milliseconds; buying again adds time): a paid call for n ms at the `time` rate; the n ms are credited to the
   paying channel once the payment settles, once per payment.
2. `POST <base>/line {"op": "open", "channelId"}` (or the free `line` tool): a message to sign with the payer key.
3. `{"op": "prove", "channelId", "nonce", "signature"}`: the credential; the meter is on. A nonce is one attempt, 300 s.
4. Calls to a `meter="time"` tool with the credential in `x-line` (MCP: `_meta["zeam-pass/line"]` or the request's
   `x-line`) run until the time runs out; answers carry `x-pass-ms-remaining` and `x-pass-ms-elapsed` (MCP:
   `_meta["zeam-pass/meter"]`). Time burns while a call runs, once for calls at the same time, plus `idleMs` after each.
   Refusals: 403 `line_unknown`, 402 `meter_off`, `out_of_time`, `channel_leaving`.
5. `{"op": "off"}`, `"on"`, `"status"`, `"close"` with `credential` or `x-line`.

`zeam_pass.deadline_ms()` inside a time tool: the epoch ms its call must end by (`None` elsewhere). The call runs in a
worker thread; past the deadline the caller gets `out_of_time` and a late result is discarded, so stop by then.
Claims and payouts take what burned; a refund switches the meter off, returns the unburned time with the balance
(`timeReturnedMs`) and closes the channel's lines. State: `<state_dir>/meter`.

## Refunds

The buyer posts `{"channelId", "issued", "signature"}`, signed by the channel's payer over
`ZEAM Pass refund\nchannel: <id>\nissued: <ISO time>` within 5 minutes, plus `channelConfig` for a channel with no
calls. The balance goes back through the relay:

- The channel's spend times the fee address's share of your split (9.99%; 100% with `payout_is_fee_recipient`), in
  whole micro-dollars, ≥ its deposit and refund gas × 1.15: ZEAM pays the gas. `"op": "refunded"`, `gasMicroUSD: 0`.
- Otherwise: 409 `"op": "refund_quote"` (`gas_payment_needed`), an EIP-3009 `TransferWithAuthorization` of the quoted
  gas (15% margin) for the buyer to sign. The relay prices it; relay unreachable: the engine prices from `GAS`
  (`quotedBy: "engine"`). Public check: `https://api.zeampass.com/relay/quote`. The buyer posts again with
  `"gasPayment"`; the relay sends the refund, then the payment, in one transaction.
- `"selfSend": true`: the buyer gets a signed refund of the full balance and sends it with ETH on Base for gas.
- 1 refund per channel per hour; sooner: `refund_too_soon` with `retry_after_seconds`.

Every field and error: https://zeampass.com/docs/agents#errors and
https://zeampass.com/docs/agents#refunds

## Background work

A daemon thread runs `agents.tick()` every `tick_seconds` (60): it stamps channels whose payer started a withdrawal,
claims earnings once the fee covers the gas and pays them out in the same relay request, and, in `gate` mode, buys
credit. Several workers can share one `state_dir`; overlapping ticks skip. `tick_seconds=0` and `agents.tick()` from
your cron schedules it yourself. `agents.payout_now()` pays out now, or returns the transaction to send from your
wallet with ETH on Base for gas.

A paid call waits on the chain and, for a deposit, on the relay: serve with threads or workers (gunicorn, uvicorn, or
`wsgiref` with `socketserver.ThreadingMixIn` as in `examples/server.py`).

## Tests

`python -m unittest` from this directory runs the plugin tests and the 5 fixture sets every engine shares
(`wordpress/tests/vectors.json`, `settlement/fixtures.json`, `gate/fixtures.json`, `pricing/fixtures.json`,
`meter/fixtures.json`).
