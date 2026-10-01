# @zeam-labs/pass

ZEAM :: Pass for your own server. Define your tools once; agents pay per call over MCP and x402 HTTP on your domain.
The Pass engine runs in your process. Your settle key, channel records and settlement stay on your server. You keep
90.01% of every sale, paid to your wallet through a split contract with no owner; ZEAM's relay pays the gas.

Source: https://github.com/zeam-labs/pass

## Install

Published: `npm install @zeam-labs/pass` (Node ≥18).

## Start

```js
import { pass } from '@zeam-labs/pass'

const agents = pass({
  name: 'acme',
  site: 'https://acme.example',
  mode: 'paywall',
  price: '0.02',
  payout: '0xYourWalletAddress',
})
agents.tool({
  name: 'add',
  description: 'Adds two numbers.',
  inputSchema: {
    type: 'object',
    properties: { a: { type: 'number' }, b: { type: 'number' } },
    required: ['a', 'b'],
  },
  run: async ({ a, b }) => ({ sum: a + b }),
})
const handle = agents.handle('/agents')
```

Serve it one way. A fetch host:

```js
export default {
  fetch: async (request) =>
    (await handle(request)) ?? new Response('not here', { status: 404 }),
}
```

Express:

```js
app.use(agents.express('/agents'))
```

`payout` must be a real address; `pass()` throws on a placeholder. `examples/server.mjs` is a whole server on
`node:http`:

    PASS_NAME=acme PASS_PAYOUT=0xYourWalletAddress PASS_PRICE=0.02 \
      node examples/server.mjs

With no `name`, `pass()` reads ./pass.json (or the file named by `config`):

```json
{
  "name": "acme",
  "site": "https://acme.example",
  "mode": "paywall",
  "price": "0.02",
  "payout": "0xYourWallet"
}
```

Routes:

- `/agents/mcp`: MCP. Tools are listed free. A call carries the x402 PaymentPayload as a JSON object (not base64) in
  `_meta["x402/payment"]`; an unpaid call answers with the x402 terms.
- `POST /agents/v1/<tool>`: the same tools over HTTP, with the x402 `PAYMENT-SIGNATURE` header.
- `/agents/openapi.json`: the tools as OpenAPI.
- `POST /agents/refund`: buyer refunds (see Refunds).

The agent side: [zeampass.com/docs/agents](https://zeampass.com/docs/agents).

## Options

- `config` (default `./pass.json` when no `name` is given): a path to a JSON
  file of these options, or the object.
- `name`: 2 to 32 of a-z, 0-9 and -, starting with a letter; fixes your
  split's address.
- `payout`: the wallet address earnings go to; a gate's zero-value proofs name
  it, and nothing is paid to it.
- `mode` (default `paywall`): `paywall`, `gate` or `both`.
- `price`: USD per call, up to 6 decimals (`"0.02"`); the price of a tool with
  none of its own; required for `paywall` and `both` unless `prices` is set.
- `prices` (default none): per tool:
  `{ report: '0.05', words: { price: '0.05', unit: '0.0001' } }`, or
  `(tool, args) => price`.
- `free` (default `[]`): tool names served with no payment and no admission.
- `time` (default none): line time:
  `{ block: '0.00025', blockMs: 250, idleMs: 0, maxBlocks: 14400 }`;
  adds `buy_time`, `line` and `POST <base>/line`.
- `freeLimit` (default none): free calls an hour per client address per tool
  (`60` or `{ perHour: 60 }`); over it: 429 `free_limit`.
- `site` (default the request's origin): your public origin, used in the
  402's resource and refund URL.
- `admit` (default `[]`): the addresses of the keys a gate admits (`gate` and
  `both`).
- `onEmpty` (default `refuse`): gate credit at 0: `refuse`, or `allow` and
  warn.
- `contact` (default none): an `https://` URL or a `mailto:` address; goes in
  every 402, in the 403 `refused` answer (`how` and `contact`) and in
  `openapi.json` `info.contact`.
- `stateDir` (default `$PASS_STATE_DIR` or `./.pass`): keys, channel records,
  gate usage; relative to the working directory.
- `rpc` (default `https://mainnet.base.org`): a Base RPC URL, or an object
  with `call(method, params)`.
- `relay` (default `https://api.zeampass.com/relay`): ZEAM's relay.
- `credits` (default `https://api.zeampass.com/credits`): ZEAM's gate credit
  service.
- `tick` (default `60`): seconds between background runs; `false` turns the
  timer off.
- `keys`: `{ settle, credit }` private keys in place of the ones in
  `stateDir`.
- `keySecret` (default `$PASS_KEY_SECRET`): seals `keys.json` (see Keys).
- `refundUrl` (default `<site>/<base>/refund`): the refund URL the 402 names.
- `feeRecipient` (default `$PASS_FEE_RECIPIENT` or ZEAM's fee address):
  development only.
- `payoutIsFeeRecipient` (default `false`): `true` only when `payout` is the
  fee address: the split pays it 100%.
- `creditIssuer` (default `$PASS_CREDIT_ISSUER` or ZEAM's issuer): development
  only: the key whose credit notes the gate accepts.
- `stores` (default file stores): `{ channels, gate }` in your own database
  (see State).
- `timeouts` (default `{ rpc: 10000, relay: 120000, credits: 20000 }`):
  milliseconds.
- `serverName`, `version` (default `name`, `1.0.0`): the MCP handshake's
  server info.

A bad `name` or `payout`, or a paywall without a price, throws when `pass()` is called.

## Prices per tool

A tool's price: its own `price`, else `prices[tool]`, else `price`. A bad price throws at `tool()`; a `prices`
function that throws answers 500 `price_invalid`, nothing held.

```js
agents.tool({ name: 'report', price: '0.05', run })
agents.tool({ name: 'ping', free: true, run })
agents.tool({
  name: 'words', price: '0.05', unit: '0.0001',
  run: async ({ text }, meter) => {
    meter.units(text.length)
    return { ok: true }
  },
})
```

`report`: $0.05 per call. `ping`: no payment, no admission. `words`: reserves $0.05, charges $0.0001 × units, at most
$0.05.

- The 402 states this call's `amount` and `pricing`, and `prices`: every tool's tag. `tools/list` carries each
  tool's tag in `_meta["zeam-pass/price"]`; `openapi.json` in each operation's `x-price`.
- Tags: `{"usd":"0.02","per":"call"}`, `{"usd":"0.0001","per":"unit","upTo":"0.05"}`,
  `{"usd":"0","per":"call","free":true,"perHour":60}`, `{"per":"call","varies":true}` (a `prices` function);
  with `time`: `buy_time` `{"usd":"0.00025","per":"block","blockMs":250,"maxBlocks":14400}` and each time tool
  `{"per":"time","blockUSD":…,"blockMs":…,"callUSD":…,"callMs":…}`.
- Usage: `meter.units(n)`, n a whole number ≥ 0. Charge = n × `unit`, at most the reserve; the rest of the reserve
  stays in the buyer's channel. `PAYMENT-RESPONSE` `extra` carries `chargedAmount` and `reservedAmount`, on every settled call. No units
  reported, or a bad n: the call fails, released, nothing charged.
- Free: validated, no payment header, 429 `free_limit` with `retry_after_seconds` past `freeLimit`. `handle()` counts
  per `info.ip` (`handle(base)(request, { ip })`); `express()` passes `req.ip`; no `ip`: one count shared by all
  callers. The count is kept in `stateDir/free.json`, so a restart does not reset the hour.
- Time: `agents.tool({ name: 'scan', meter: 'time', run: async (args, { signal, deadline }) => ... })`. On a line
  (`x-line`) it runs until `deadline` (`signal` aborts then) and burns the line's time; without a line it is a paid
  call bounded to its price's ms. Calls, `buy_time`, the line steps and refunds:
  [Line time](https://zeampass.com/docs/agents#line-time).
- `paid()`, `instrument()` and `paywall` take prices by tool name (`prices`) or `{ price }`; usage metering and free
  limits are for `tool()`.

## A call

1. The arguments are checked against the tool's `inputSchema` (`type: object`, `required`, the types `string`,
   `number`, `integer`, `boolean`, `object`, `array`, `null`, `enum`, `minimum`, `maximum`, `minLength`,
   `maxLength`, and the same for an object argument's properties, 1 level deep). Bad arguments: HTTP 400 (MCP: an
   `isError` result); nothing held, nothing charged, no chain read.
2. **paywall:** no payment answers 402 with the x402 `batch-settlement` terms. A payment is verified as the x402
   SDK's facilitator verifies it (voucher signature, channel binding, chain balance, cumulative amount) and the
   channel is held. **gate:** the zero-value x402 `exact` signature is verified offline (nothing is paid; the key
   needs no funds), replay is refused, grants are checked, 1 check is counted. **both:** the payer must be admitted
   before anything is held; paid calls are not gate checks.
3. Your tool runs; its result is serialized to JSON.
4. Success: the payment settles. A voucher is committed to your channel record; a first deposit goes on chain
   through the relay. The result carries the `PAYMENT-RESPONSE` header (MCP: `_meta["x402/payment-response"]`).
   Failure (the tool threw, returned `isError: true`, or an HTTP route answered 400 or above): the hold is released,
   the buyer gets HTTP 500 `tool_failed`, nothing charged. The same for a result that is not valid JSON (a
   non-finite number, a BigInt, a circular object, an empty array slot, a `Date`, a class instance, anything
   `JSON.stringify` changes or drops, or no result), with the message every engine gives: "the result is not valid
   JSON (a non-finite number or a non-JSON value); nothing was charged".

Settlement fails: the result is withheld; the buyer gets a 402 with the terms and the reason. Chain unreadable: the
payment is refused.

## The gate

`mode: 'gate'` admits the keys in `admit`, free to them; `both` admits them and they pay per call. An agent signs
each call with its key as a zero-value x402 payment (any x402 client makes one): nothing is paid, the key needs no
funds or ETH, nothing goes on chain. Agent side: [Pass a gate](https://zeampass.com/docs/agents#pass-a-gate).

```js
const agents = pass({
  name: 'acme',
  mode: 'gate',
  payout: '0xYourWalletAddress',
  admit: ['0xAgentKeyAddress'],
})
```

A key in `admit` can admit one other key with a grant. `signGrant` returns the `x-grant` header value for the agent
that holds that key:

```js
import { signGrant } from '@zeam-labs/pass'

const grant = await signGrant({
  key: process.env.ADMITTED_KEY,
  delegate: '0xTheirKeyAddress',
  scope: 'add',
  until: '2026-12-31T00:00:00Z',
})
```

`key`: the private key of an address in `admit`. `delegate`: the address of the key you admit. `scope`: one tool
name, or `'*'` (default). `until`: an ISO time or a `Date`. A call signed by any other key is refused `bad_grant`; so
is one past `until`. Removing the signing key from `admit` ends every grant it signed. A key:
`generatePrivateKey()` from `viem/accounts`; `privateKeyToAccount(key).address` is the address to list.

## Keys

On first run the engine writes its keys to `stateDir/keys.json`, mode 0600: the settle key (signs your claims and
refunds; the `receiverAuthorizer` of every channel) and, for `gate` and `both`, the credit wallet, which holds the
gate's USDC. `agents.status()` shows its address (`creditWallet.address`); `keys: { credit }` sets your own. Gate
mode: send it USDC on Base; it needs no ETH. Credit is 2,000 checks at a time for $1 USDC ($0.50 per 1,000), bought
after the month's 10,000 free checks are used and again below 200 remaining. Keep $1 USDC on Base in the credit
wallet. `both` mode needs no USDC in the credit wallet: paid calls are not gate checks.

`PASS_KEY_SECRET` seals the keys with AES-256-GCM (key from scrypt); an unsealed file is sealed on the next start.
Back up `keys.json` and the secret: without the settle key, your channels cannot be claimed.

## State

`stateDir` holds channel records (the SDK's field layout plus `handedOver`, `depositsWePaid`, `repairedAt`), gate
usage, replay protection and the last run's report, written atomically under a lock; several processes can share
one disk. Without a persistent disk, give your own stores:

```js
pass({ ..., stores: {
  channels: {
    get: async (channelId) => record | null,
    update: async (channelId, fn) => next,
    list: async () => records,
  },
  gate: {
    get: async (key) => value | null,
    update: async (key, fn) => result,
  },
} })
```

`update` is atomic per id: read the current value, call `fn(current)`, store the return (channels: `null` deletes;
gate: `fn` returns `[next, result]` and `update` returns `result`).

## Background work

Every `tick` seconds, on a timer that does not keep the process alive, the engine stamps channels whose payer started
a withdrawal, claims idle channels and pays you out through the relay once the 9.99% fee covers the gas, and, for a
gate, buys credit. Serverless: set `tick: false` and call it from your cron:

```js
const agents = pass({
  name: 'acme', payout: '0xYourWalletAddress', price: '0.02', tick: false,
})
const handle = agents.handle('/agents')
export default {
  fetch: async (request) =>
    (await handle(request)) ?? new Response('not here', { status: 404 }),
  scheduled: () => agents.tick(),
}
```

- `tick(options)` (package export): makes a Pass from the options (or `./pass.json`) with the timer off and runs once.
- `agents.tick()`: 1 run.
- `agents.payout()`: pays out now through the relay, or returns a transaction to send from your wallet with ETH on
  Base for gas.
- `agents.status()`: unclaimed, paid, gate usage. Fields: [Status](https://zeampass.com/docs/sell#status).
- `agents.stop()`: stops the timer.
- `agents.verify(url)`: whether your `site` shares that URL's origin.

## An MCP server you already have

With the official SDK, make its tools paid in place:

```js
const server = agents.instrument(
  new McpServer({ name: 'mine', version: '1' }),
  { free: ['about'] },
)
server.registerTool('quote', { inputSchema: { symbol: z.string() } }, handler)
```

`instrument()` returns the server. Tools the official SDK holds when `instrument()` runs are made paid too (except
the `free` ones); on another server object, a tool registered before `instrument()` stays free. One handler:
`server.registerTool('quote', config, agents.paid('quote', handler, { description }))`. The SDK checks the arguments
before the handler runs.

## Routes you already have

`app.use('/reports', agents.paywall.express())`, or `agents.paywall.wrap(handler)` for a fetch handler. The Express
paywall buffers the whole response until the payment settles, then sends it with `PAYMENT-RESPONSE`; a stream is
sent when it ends. `paywall.wrap()` settles when your handler returns its `Response`, before a streamed body is read:
a stream that fails partway is charged. Give `wrap()` a complete body, or use the Express paywall.

## Refunds

`agents.handle()` and `agents.express()` serve `POST <base>/refund`. With `instrument()`, `paid()` or the paywall,
mount it: `app.post('/refund', agents.refundExpress())`, or `agents.refund(request)` in a fetch handler. The refund
URL the 402 names is `site` + the mount path when `site` is set, else the request's host.

The buyer posts `{ channelId, issued, signature }`, signed by the channel's payer over
`ZEAM Pass refund\nchannel: <id>\nissued: <ISO time>` within 5 minutes, plus `channelConfig` for a channel with no
calls. The balance goes back through the relay:

- The channel's spend times the fee address's share of your split (9.99%; 100% with `payoutIsFeeRecipient`),
  rounded down, ≥ its deposit and refund gas × 1.15, rounded up: ZEAM pays the gas. `op: "refunded"`, `gasMicroUSD: 0`.
- Otherwise: 409 `op: "refund_quote"` (`gas_payment_needed`), an EIP-3009 `TransferWithAuthorization` of the quoted
  gas (15% margin) for the buyer to sign. The relay prices it (`quotedBy: "relay"`); relay unreachable: the engine
  prices from `Config.GAS` and the measured L1 fee (`quotedBy: "engine"`). Public check:
  `https://api.zeampass.com/relay/quote`. The buyer posts again with `gasPayment`; the relay sends the refund, then
  the payment, in one transaction.
- `selfSend: true`: the buyer gets a signed refund of the full balance and sends it with ETH on Base for gas.
- 1 refund per channel per hour.

Every field and error: [Errors](https://zeampass.com/docs/agents#errors) and
[Refunds](https://zeampass.com/docs/agents#refunds).

## Browsers

`handle()` responses carry `access-control-allow-origin: *` and expose `PAYMENT-REQUIRED`, `PAYMENT-RESPONSE` and
`X-Pass-Granted-By`; `OPTIONS` on `/mcp`, `/v1/*` and `/refund` answers the preflight.

## Dependencies

- `viem`: keccak, secp256k1 signing and recovery, EIP-712 hashing, ABI encoding, JSON-RPC.
- `@x402/evm`: the batch-settlement escrow and collector addresses, the EIP-712 domain and types (`Voucher`,
  `Refund`, `ClaimBatch`, `TransferWithAuthorization`) and `computeChannelId`, so the engine hashes what buyers
  sign. Brings `@x402/core` and `zod`.

The engine is the Node port of the PHP engine in the ZEAM :: Pass WordPress plugin; both pass the same shared test
vectors.
