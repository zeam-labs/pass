# ZEAM :: Pass for agents

A Pass seller serves its tools on its own site:

- **MCP** at `<base>/mcp`. `tools/list` is free. A paid call carries the x402 PaymentPayload as a JSON object in
  `_meta["x402/payment"]`.
- **HTTP** at `POST <base>/v1/<tool>`, with the x402 `PAYMENT-SIGNATURE` header (base64 of the PaymentPayload JSON).
  `<base>/openapi.json` lists the tools; its `info.description` states the mode: paywall, gate or both.

No sign-up, no API key. To pay: hold USDC on Base and sign. Paying needs no ETH. A refund the seller sends needs no
ETH. `selfSend`, `initiateWithdraw` and `finalizeWithdraw` are transactions from your wallet and need ETH on Base for
gas. To pass a gate: sign with your key; nothing is paid and the key needs no funds.

## 1. Pay with your own wallet

Hold USDC on Base. No approval.

The ZEAM :: x402 Bridge pays from a key in your environment. Set `X402_MCP_URL` to the seller; without it the Bridge
calls its default upstream.

    export X402_PRIVATE_KEY=0x…
    X402_MCP_URL=https://seller.example/agents/mcp npx -y @zeam-labs/x402-mcp-bridge --tools
    X402_MCP_URL=https://seller.example/agents/mcp npx -y @zeam-labs/x402-mcp-bridge --call add '{"a":1,"b":2}'

Without `--call` or `--tools` the Bridge is an MCP stdio server.

- **Use the eip3009 row.** For USDC, the `accepts[]` row whose `extra` has `name` and `version` and no
  `assetTransferMethod` is EIP-3009: signed offline, no approval. The `"assetTransferMethod": "permit2"` row needs a
  one-time on-chain Permit2 approval, paid in ETH.
- **Deposit = price × multiplier.** The first call on a channel deposits; later calls spend the deposit. Multiplier:
  5 for the stock x402 SDK; `X402_DEPOSIT_MULTIPLIER` for the Bridge (default 40, minimum 3). The deposit must reach
  the floor in the 402's `deposit` line; below it the answer is `funding_requires_open_fee` with `neededMicroUSD`.
  Raise the multiplier until price × multiplier ≥ floor.
- **A failed call is not charged.** Arguments are checked first; a failed tool releases the hold. An unpaid call
  with no arguments (no body, an empty body or `{}`) gets the 402 with the price. A call the tool
  completes is charged, even when the connection drops before the answer arrives. Settlement comes
  back in the `PAYMENT-RESPONSE` header (MCP: `_meta["x402/payment-response"]`).

A connector link from a pass: add it as a custom MCP connector. It pays the pass's seller; its free `pass_balance`
tool states the balance. Section 6 funds one.

## 2. Read a seller's price

Call a tool without paying:

- **HTTP:** `402`. The `PAYMENT-REQUIRED` header is base64 JSON of the terms; the body carries the same.
- **MCP:** `isError: true`; `structuredContent` is the terms.

`accepts[].amount` is the price per call in micro-USDC: `"20000"` is $0.02. `pricing` states it in dollars
(`"$0.02 per call. A failed call is not charged."`). `deposit` states the minimum first deposit
(`"First call on a channel deposits at least $0.03. Later calls spend it. The unspent balance is refundable."`).
`refund` states the refund rule (section 5). A gate's amount is `"0"`.

Each tool has its own price. `prices` in the 402 tags every tool; `tools/list` tags each tool in
`_meta["zeam-pass/price"]`; `openapi.json` in each operation's `x-price`:

| Tag | Meaning |
|---|---|
| `{"usd":"0.02","per":"call"}` | $0.02 per call |
| `{"usd":"0.0001","per":"unit","upTo":"0.05"}` | usage: sign $0.05; charged $0.0001 × the units the tool reports, at most $0.05 |
| `{"usd":"0","per":"call","free":true,"perHour":60}` | free: no payment, no admission; 60 calls an hour per address, then 429 `free_limit` |
| `{"per":"call","varies":true}` | depends on the arguments; the call's 402 states it |
| `{"usd":"0.00025","per":"block","blockMs":250,"maxBlocks":14400}` | `buy_time`: $0.00025 per block of 250 ms of line time, 1 to `maxBlocks` blocks a call (see Line time) |
| `{"per":"time","blockUSD":"0.00025","blockMs":250,"callUSD":"0.01","callMs":10000}` | a time tool: on a line it burns line time; without one, $0.01 a call for up to 10,000 ms |

Usage: sign `accepts[].amount`, the reserve. `PAYMENT-RESPONSE` `extra` states `chargedAmount` (used) and
`reservedAmount` on every settled call, equal when the whole reserve was charged; the rest stays in your channel. Sign the next voucher on `extra.channelState.chargedCumulativeAmount`
(stock x402 clients do).

### Line time

A seller that sells time lists `buy_time` and `line`, tags each time tool
`{"per":"time","blockUSD":"0.00025","blockMs":250,"callUSD":"0.01","callMs":10000}`, and states the rule in the
402's `time` line.

1. **Buy.** Call `buy_time` `{"blocks": N}` and pay N × `blockUSD` (its tag is `per: "block"`). The time goes to the
   paying channel with the payment: time that cannot be recorded is not charged (500 `tool_failed`), and a payment
   that does not settle credits no time. The answer states `boughtMs` and `msRemaining`.
2. **Open a line.** `POST <base>/line` `{"op":"open","channelId"}` answers `{nonce, sign}`. Sign `sign` (EIP-191)
   with the channel's payer key (or `payerAuthorizer`), then `POST` `{"op":"prove","channelId","nonce","signature"}`:
   the answer carries `credential`. A nonce is one attempt, valid 300 s. MCP: the free `line` tool, same arguments.
3. **Call.** Send the credential as the `x-line` header (MCP: `_meta["zeam-pass/line"]`, or `x-line` on the MCP
   request). No payment per call. The answer carries `x-pass-ms-remaining` and `x-pass-ms-elapsed` (MCP:
   `_meta["zeam-pass/meter"]`).
4. **Burn.** Time burns while a call runs on the line; calls at the same time burn one clock. A call stops when the
   time runs out: 402 `out_of_time`, its time spent.
5. **Meter.** `{"op":"off"}`: no new calls on the line; a running call burns to its end; a call sent meanwhile gets
   402 `meter_off` with `msRemaining`, the time the line still holds. `{"op":"on"}`, `{"op":"status"}`,
   `{"op":"close"}`, each with the credential. Over HTTP the line is `POST <base>/line`, or `POST <base>/v1/line`
   with the same body; both answer a failed step with its status and `{op: "line_failed", code, why}`.
6. **Leave.** A refund (section 5) returns the unburned time with the balance (`timeReturnedMs`) and closes the line.

Without a line a time tool is a paid call at `callUSD`, for up to `callMs`; a call past it: 402 `out_of_time`, not
charged.

## 3. Pass a gate

A gate admits listed keys. Each call is signed with your key as a zero-value payment: nothing is paid, the key needs
no funds or ETH, nothing goes on chain.

**Make a key** once. Keep the private key; give the seller its address.

```js
import { generatePrivateKey, privateKeyToAccount } from 'viem/accounts'
const key = generatePrivateKey()
privateKeyToAccount(key).address
```

```python
from zeam_pass import key_address, new_key
key = new_key()
key_address(key)
```

**Sign every call.**

1. Call without a proof. The 402 has one `exact` requirement: `amount: "0"`, `payTo` the seller's wallet,
   `realm: "ZEAM Pass"`, and `how`.
2. Sign it with any x402 client that signs `exact` (an EIP-3009 `TransferWithAuthorization` of value 0, never sent
   to the chain). Send it as `PAYMENT-SIGNATURE` (MCP: `_meta["x402/payment"]`).
3. Use a new nonce per call. A signature is accepted once (again: `replayed`) and is valid 300 seconds.

Node, with the x402 client:

```js
import { createPublicClient, http } from 'viem'
import { base } from 'viem/chains'
import { privateKeyToAccount } from 'viem/accounts'
import { x402Client, wrapFetchWithPayment } from '@x402/fetch'
import { toClientEvmSigner } from '@x402/evm'
import { ExactEvmScheme } from '@x402/evm/exact/client'

const signer = toClientEvmSigner(privateKeyToAccount(process.env.AGENT_KEY), createPublicClient({ chain: base, transport: http() }))
const f = wrapFetchWithPayment(fetch, new x402Client().register('eip155:8453', new ExactEvmScheme(signer)))
const r = await f('https://seller.example/agents/v1/search', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{"q":"solar"}' })
```

Python, with `zeam-pass` (`gate_proof` signs the zero-value row of the terms):

```python
import json, os, urllib.error, urllib.request
from zeam_pass import gate_proof

def call(url, body, grant=None):
    def post(headers):
        req = urllib.request.Request(url, json.dumps(body).encode(), {"content-type": "application/json", **headers}, method="POST")
        return urllib.request.urlopen(req)
    try:
        return post({})
    except urllib.error.HTTPError as e:
        if e.code != 402:
            raise
        proof = gate_proof(os.environ["AGENT_KEY"], e.headers["PAYMENT-REQUIRED"])
        return post({"payment-signature": proof, **({"x-grant": grant} if grant else {})})
```

**Getting admitted.** A key not on the list gets `403 refused` with a `how`. The seller's `contact` (an `https://`
page or a `mailto:` address), when set, is in that answer, in every 402 and in `openapi.json` `info.contact`. A
WordPress seller's default is its site address. Send the seller your key's address, or ask for a grant.

**Grants.** A listed key can admit your key with a grant: your key's address, one tool or all (`*`), an end time.
Send `x-grant: <base64url of JSON {delegate, scope, until, signature}>` on every call, and sign the call with your own
key as above. The grant works only for the named key. It ends at `until`, or when the seller removes the signing key.
`signature` is the admitting key's EIP-191 signature of:

```
ZEAM Pass grant
delegate: <your key's address, lowercase>
scope: <tool, or *>
until: <ISO time>
```

The admitting side signs with `signGrant` (Node, `@zeam-labs/pass`) or `sign_grant` (Python, `zeam-pass`):
[seller/docs/SELL.md](../seller/docs/SELL.md). `x-grant` is an HTTP header, on `POST <base>/mcp` too, where it covers
every tool call in the request; never in `_meta`. An answer through a grant carries
`X-Pass-Granted-By: <admitting key's address, lowercase>` (MCP: `_meta["zeam-pass/granted-by"]`). With
`@x402/fetch`, add `'x-grant': grant` to the request headers.

**Both mode.** The key must be admitted (directly or by `x-grant`) and the call paid from it. The unpaid 402 has an
`admission` field and `batch-settlement` rows in `accepts`; a gate has an `exact` row. With `@x402/fetch`, register
`BatchSettlementEvmScheme` beside `ExactEvmScheme`; its signer reads your channel on Base and sends no transaction.

```js
import { BatchSettlementEvmScheme } from '@x402/evm/batch-settlement/client'

const client = new x402Client()
  .register('eip155:8453', new BatchSettlementEvmScheme(signer))
  .register('eip155:8453', new ExactEvmScheme(signer))
const pay = wrapFetchWithPayment(fetch, client)
const r = await pay('https://seller.example/agents/v1/search', { method: 'POST', headers: { 'content-type': 'application/json', 'x-grant': grant }, body: '{"q":"solar"}' })
r.headers.get('x-pass-granted-by')
```

The Bridge sends `X402_GRANT` as `x-grant` on every request, over MCP and HTTP, from 3.0.0.
`seller/test/grant-client.test.mjs` runs the client above against a reference seller in `both` and gate mode.

## 4. Errors

HTTP answers carry `error` and `message`, and some carry `code`, `reason`, `how` and `contact`. An MCP `isError`
result carries the same fields in `structuredContent`, merged with the x402 terms when the answer has them. A payment
that does not verify: `error` is the x402 code (`invalid_batch_settlement_evm_…`), the same as in the
`PAYMENT-REQUIRED` terms, and `code` is `payment_invalid`; resync the channel from `accepts`. The refund route answers
`op: "refund_failed"` with `code` and `why`, `op: "refund_quote"` when there is something to sign, `op: "refunded"`
when it was sent, or `op: "refund_signed"` for `selfSend` (section 5).

| code | status | meaning | next step |
|---|---|---|---|
| `invalid_arguments` | 400 | the arguments do not match the tool's `inputSchema`, or the body is not JSON; nothing held. An unpaid call with no arguments gets the 402 instead | fix the arguments from `tools/list` |
| `unknown_tool` | 404 | no tool by that name; HTTP lists the tools | use a name from `tools/list` |
| `tool_failed` | 500 | the tool failed after the hold, its result is not valid JSON, or a usage tool reported no units; hold released, nothing charged | retry later, or with other arguments |
| `not_found` | 404 | (WordPress) no published post with that id; nothing charged | find an id with `search_posts` |
| `internal_error` | 500 | the seller's server failed | retry later |
| `price_invalid` | 500 | the seller cannot price this call; nothing held | retry later, or with other arguments |
| `line_unknown` | 403 | the `x-line` credential names no open line (closed, or refunded) | open a line |
| `meter_off` | 402 | the line's meter is off; `msRemaining` is the time the line still holds | `POST <base>/line {"op":"on"}` |
| `out_of_time` | 402 | the line has no time left, or the call ran past its time; that time is spent. Without a line: past `callMs`, not charged | `buy_time`, then call again |
| `no_challenge`, `bad_signature` | 400 | (line route) the nonce is used, expired or unknown, or the signature does not recover | `{"op":"open"}` again and sign `sign` |
| `free_limit` | 429 | over the free tool's calls an hour per address (the seller's count survives its restarts; callers the seller sees no address for share one count); `retry_after_seconds` | retry after `retry_after_seconds` |
| `body_too_large` | 413 | (Python) the body is over 1 MB | send a smaller body |
| `not_ready` | 503 | (WordPress) the seller's setup is incomplete | retry later |
| `payment_unavailable` | 503 | the payment check or settlement failed; nothing charged | retry |
| (none, with `accepts`) | 402 | payment required; for a gate, a signed proof of key | pay one row of `accepts` (section 1), or sign the zero-value row (section 3) |
| `bad_payment_header` | 400 | the payment does not decode | send base64 of the PaymentPayload JSON (MCP: the object) |
| `bad_payment_type` | 400 | the payment is neither a voucher nor a deposit | pay with a voucher or a deposit |
| `no_matching_requirement` | 402 | the payment matches no row of `accepts` | sign a row as given |
| an x402 code, with `code: "payment_invalid"` | 402 | the payment did not verify (signature, channel, balance or cumulative amount); `error` and `reason` name it | re-sign from the returned `accepts`, which carry the channel's current state |
| `price_changed` | 402 | the price differs from the one signed | pay the `accepts` quote |
| `funding_requires_open_fee` | 402 | the deposit is under the floor | deposit at least `neededMicroUSD` |
| `channel_leaving` | 402 | the channel is withdrawing | open a new channel |
| `refund_outstanding` | 402 | the channel has an unsent signed refund | send it (the transaction in the answer), or open a new channel |
| `settle_failed` | 402 | the payment did not settle; not delivered, nothing charged | call again with a new payment |
| `no_payment` | 402 | (gate) no `PAYMENT-SIGNATURE`, or not base64 JSON of an x402 payment | sign the zero-amount requirement with your key |
| `bad_payment` | 402 | (gate) no authorization in the payment | send an `exact` EIP-3009 payment |
| `invalid_payment` | 402 | (gate) the signature, amount, recipient or time window is wrong; `message` names it | re-sign as given: value 0, its `payTo`, a future `validBefore` |
| `replayed` | 402 | (gate) this authorization was already used | sign again with a new nonce |
| `bad_grant` | 402 | (gate and `both`) `x-grant` is malformed or expired, its signer is not admitted, or it names another delegate than the key that signed the call; `message` names it; nothing held | get a new grant from an admitted key, naming the key you sign with |
| `refused` | 403 | your key is not admitted, or your grant's scope is another tool; `how` says how to get in; `contact`, when set, says where | ask the seller to admit your key's address, or ask an admitted key for a grant; do not retry as is |
| `gate_credit_exhausted` | 503 | this gate has no checks left this month | retry later |
| `invalid_json`, `invalid_request`, `bad_request` | 400 | (refund route) the body is not a JSON object | send `{channelId, issued, signature}` |
| `not_a_paywall` | 404 | (refund route) this seller takes no payments | none |
| `unknown_channel` | 404 | (refund route) no channel with that id here; a channel with no calls is found by its `channelConfig` | check the id, or send `channelConfig` with the proof |
| `channel_config_mismatch` | 400 | (refund route) the `channelConfig` is not this seller's channel (checked whenever sent); `mismatch`: `channelId` (hashes to another id; `why` names both), `receiver` or `receiverAuthorizer` (another seller), `token` (not accepted here), `fields` (not the 7 channel fields), or `balance` (the channel holds nothing on chain) | send the config as deposited, to the seller it names |
| `proof_invalid` | 400 | (refund route) the signed proof does not verify or is stale; `sign` shows the text | sign that text within 5 minutes |
| `not_the_payer` | 403 | (refund route) the proof is not signed by the channel's payer | sign with the payer's key |
| `request_open` | 409 | (refund route) a paid call is open on the channel | retry after `retry_after_seconds` |
| `nothing_to_return` | 409 | (refund route) the balance is 0, or refund gas ≥ balance; `returnedMicroUSD` 0 | read `leftMicroUSD` and `gasMicroUSD` |
| `chain_unreadable` | 409 | (refund route) the chain read failed | retry after `retry_after_seconds` |
| `gas_unpriced` | 503 | (refund route) refund gas cannot be priced, or the relay is unreachable | retry after `retry_after_seconds`, or send `"selfSend": true` |
| `refund_unavailable` | 503 | (refund route) the seller's refund handler failed | retry later |
| `refund_error` | 409 | (refund route) sending failed, nothing sent; carries `leftMicroUSD` and the gas figures; the hour is not used | retry after `retry_after_seconds` |
| `refund_too_soon` | 409 | (refund route) the channel had a refund in the last hour | retry after `retry_after_seconds`, or send `"selfSend": true` |
| `gas_payment_needed` | 409 | (refund route, `op: "refund_quote"`) channel fees (`feeMicroUSD`) < deposit and refund gas (`coverMicroUSD`); `gasMicroUSD` is the quoted refund gas, L1 fee included (`l1FeeWei`), 15% margin, priced by the relay (`quotedBy: "relay"`) or the seller (`quotedBy: "engine"`). With `requoted: true` and `sendCostMicroUSD`: the send cost rose past your payment; nothing sent; a new quote | sign `sign` (EIP-712) and POST again with `gasPayment: {authorization, signature}` within 5 minutes |

MCP transport errors are JSON-RPC: `-32700` the body is not JSON, `-32600` not a JSON-RPC request, `-32601` no such
method, `-32602` bad `params`, `-32603` the server failed.

## 5. Refunds

`POST <base>/refund` (the 402's `refund` line names the URL) with `{channelId, issued, signature}`. `signature` is
the payer's (or `payerAuthorizer`'s) EIP-191 signature of:

```
ZEAM Pass refund
channel: <channel id, lowercase>
issued: <ISO time, within 5 minutes>
```

A channel with no calls: add `channelConfig`, the 7 channel fields as deposited. The seller checks that it hashes to
`channelId`, names this seller as receiver, and holds a balance on chain. A config that fails a check, for any
channel, is 400 `channel_config_mismatch` with `mismatch`; nothing is sent.

The rule: ZEAM pays the gas when the channel's fees (its spend times the fee address's share of the seller's split:
9.99% for most sellers, who keep 90.01%; 100% for one whose split pays the fee address everything, like Prism; the
402's `refund` names it) ≥ its deposit and refund gas × 1.15, at the current gas and ETH price. `feeMicroUSD`
rounds down, `coverMicroUSD` rounds up; free when `feeMicroUSD >= coverMicroUSD`. The seller, the relay and the 402
apply the same comparison. An unsettled call rides the refund as a claim and adds its gas to both.

- **Free:** `op: "refunded"`, `returnedMicroUSD` = the balance, `gasMicroUSD: 0`, `transaction`.
- **Not free:** 409 `op: "refund_quote"`, `code: "gas_payment_needed"`, with `feeMicroUSD`, `coverMicroUSD`,
  `gasMicroUSD` (the quote), `gasUnits` (refund + riding claim + your payment), `gasUnitsWithMargin`, `l1FeeWei`,
  `gasPriceWei`, `ethUSD`, `marginPercent`, `quotedBy` (`relay`, or `engine` when the relay did not answer),
  `returnedMicroUSD`, `payTo` (the relay's gas wallet, its `/health` `sender`), `authorization` and `sign` (EIP-712
  typed data of an EIP-3009 `TransferWithAuthorization` of `gasMicroUSD` USDC from your payer to `payTo`). Sign
  `sign` with the payer (viem: `account.signTypedData(answer.sign)`) and POST again with a new `issued` and
  `signature`, plus `gasPayment: {authorization, signature}`. One transaction sends the refund, then the gas payment;
  you need no USDC or ETH beforehand. Answer: `op: "refunded"`, `returnedMicroUSD` (out of the escrow),
  `gasMicroUSD` (your payment); net = `returnedMicroUSD - gasMicroUSD`.
- **Refund gas ≥ balance:** 409 `nothing_to_return`; `leftMicroUSD` stays in the channel, `returnedMicroUSD` 0.
- **`"selfSend": true`:** 200 `op: "refund_signed"`, `microUSD` (the full balance), `transaction`
  (`{chainId, to, data, value}`, a signed refund you send from any wallet with ETH on Base for gas), `why`, and
  `timeReturnedMs` when line time was returned. Asked again before it is sent: the same `transaction`.
- **No answer from the seller:** `initiateWithdraw` from the payer's wallet, then `finalizeWithdraw` after the
  channel's `withdrawDelay` (it pays the payer), each with ETH on Base for gas.

1 refund per channel per hour; sooner: `refund_too_soon` with `retry_after_seconds`.

**The quote.** The seller hands its signed refund to the relay. The relay prices it: the gas the refund uses (`eth_simulateV1` from its
sender; `eth_estimateGas` where the RPC lacks it), + the gas a payment adds (median of its paid refunds), + Base's L1 data fee, at the current gas and
ETH price, + 15%. On the relay's paid refunds the quote is 1.14 to 1.18 × the send cost. Relay unreachable: the
seller prices from the same measurements (`quotedBy: "engine"`). At send time the relay estimates the transaction and
its L1 fee at current prices, and sends when your payment covers that cost. If the cost rose past your payment,
nothing is sent: 409 `refund_quote` with `requoted: true`, `sendCostMicroUSD`, and a new `authorization` and `sign`
for that cost + 15%. Sign and POST again. The signature is valid 5 minutes.

**Check the price.** The relay answers anyone, free, with no key.

- `GET https://api.zeampass.com/relay/quote` prices by shape. `?claim=1`: an unsettled call's claim rides the refund.
  `?payment=0`: a refund alone (ZEAM pays the gas). Default: refund + your gas payment, no claim.
- `POST https://api.zeampass.com/relay/quote` with `{"call": {"to": <escrow>, "data": <the refund>}, "payment": true}`
  prices one refund: `refundWithSignature`, or the escrow `multicall` of claim then refund (the calldata of a
  `selfSend` answer).

```json
{"op": "refund_gas_quote", "shape": {"claim": false, "payment": true}, "gasUnits": 155600, "gasFrom": "measured",
 "gasPriceWei": "6000000", "l1FeeWei": "80000000000", "ethUSD": 4000, "costMicroUSD": 4055, "marginPercent": 15,
 "quoteMicroUSD": 4663, "payTo": "0xB3ED726D24AF7C3ffbf04219A78506170155641b",
 "measured": {"paidRefunds": 12, "paidRefundsWithClaim": 4, "calibrated": "2026-09-29"}}
```

`costMicroUSD`: the send cost now. `quoteMicroUSD`: cost + margin, the figure a seller quotes. `gasFrom`: `simulated` (the posted refund run
against the chain), `estimated` (the posted refund, gas estimate), `measured` (median of the relay's paid refunds of
that shape) or `calibrated` (starting units, before the relay's first send of that shape). Unreadable body: `400`. Chain unreadable: `503` with
`retry_after_seconds`.

**Ordering.** The payment is a `TransferWithAuthorization`, not a `ReceiveWithAuthorization`:
`receiveWithAuthorization` requires the caller to be the payee, and the caller USDC sees is Multicall3. The relay
enforces the order: the payment is sent after the refund, in the same transaction, and both land or neither lands. It
never sends the payment alone or inside an escrow call (`seller/test/relay.test.mjs`). The seller hands the relay the
refund and payment as one Multicall3 bundle, refund first, neither leg allowed to fail. The relay sends the bundle as
is, or nested in a larger Multicall3 with other buyers' calls, where each bundle can fail alone: another buyer's
failure does not undo yours, and a failing bundle undoes its own refund and payment. Anyone holding your signed
payment can submit it alone to USDC: it pays only the relay's gas wallet, only the quoted amount, within 5 minutes,
and your refund is then quoted again. Sign it only to post it, over HTTPS.

## 6. Fund a pass for any Pass seller

A pass is an allowance at one seller, for another agent or a person's chatbot: a channel with your wallet as payer, a
pass key as spender, the seller as receiver. It pays that seller up to what you load, or returns the balance to your
wallet. The page https://zeampass.com/pass runs these steps in a browser; a seller links to it with
`?seller=<its MCP URL>`. The buyer API is https://api.zeampass.com/pass (every path below is under it); connector
links are https://api.zeampass.com/c/<token>/mcp.

1. `POST https://api.zeampass.com/pass/quote {"seller": "<the seller's MCP URL or HTTP base>"}` answers `seller`: the
   terms the pass is locked to (`receiver`, its split; `receiverAuthorizer`; `token`; `withdrawDelay`; `refund`;
   `admission`, for a seller with an access list; `contact`, when set) and its prices: `pricing` (a sentence: the
   per-call range, line time apart), `priceMicro` and `priceTool` (its cheapest per-call tool), `prices` (each tool's
   tag: `{tool, usd, per: "call"}`, `{tool, usd, per: "unit", upTo}`, `{tool, per: "time", ...}`, `{tool, free: true}`,
   `{tool, varies: true}`) and `time` (`{tool, usd, blockMs, maxBlocks}` when it sells line time). It creates
   nothing.
2. Derive the pass key from your wallet: sign `passMessage(receiver)` (`buyer/src/wallet.mjs`, EIP-191); pass `n` is
   `passAt(keccak256(signature), n)`, a key and a channel salt. The token is the key's 32 bytes, base64url (`tokenOf`
   in `buyer/src/passes.mjs`).
3. `POST /issue {"seller": …, "token": …}` answers `passAddress`, `connectorUrl` and `page`. `409 pass_in_use`: pass
   `n` is funded; use `n + 1`. Without `token` the service makes a random key and returns it once; it stores no key.
   A seller with `admission` must admit the pass address: add `"grant"`, an `x-grant` value with delegate =
   `passAddress`, signed by an admitted key (section 3). No grant: `400 grant_required` ("this seller admits only
   listed keys. Get a grant for this pass first.") with `passAddress`; no pass is made. A grant for another key:
   `400 grant_wrong_delegate` with `passAddress` and `delegate`. Not an `x-grant` value: `400 grant_invalid` with
   `passAddress`. The service then asks the seller whether it admits the grant (a call carrying the grant, for the
   pass, that pays nothing): not admitted, `400 grant_not_admitted` with the seller's reason in `message`; the seller
   could not be asked, `502 grant_unchecked`; no pass is made. All carry the seller's `contact` when set. `/link`
   answers the same.
4. `POST /c/<token>/prepare {"payer": <your wallet>, "amount": "1.00", "salt": <the pass's salt>}` answers
   `typedData`: an EIP-3009 `ReceiveWithAuthorization` of `amount` from your wallet. Sign it (no gas), then
   `POST /c/<token>/deposit` with the prepare answer plus `signature`. The service sends the deposit and pays the
   gas, only into a channel to this pass's seller, in its asset.
5. `POST /c/<token>/link {"payer": <your wallet>, "salt": …}` checks the channel on chain. The `connectorUrl` then
   pays that seller. `GET /c/<token>/status`: `loaded` (all deposits; `funded` is the same figure), `spent`,
   `returned` (back to your wallet, net of gas), `gas_paid` (quoted refund gas you paid in USDC) and `remaining`,
   with each `transaction` in `returns` (`usd` net to your wallet, `gas_usd` paid out of it).
   `loaded = spent + returned + gas_paid + remaining`. The connector's free `pass_balance` tool (`loaded_usd`,
   `spent_usd`, `returned_usd`, `gas_paid_usd`, `remaining_usd`) and the pass page show the same five.
6. `POST /c/<token>/refund` returns the balance: it calls the seller's refund route with the channel config, so a
   pass with no calls is refunded too.
   - Seller quotes gas: `409 needsSignature`. Sign `sign` with your wallet and POST
     `{"gasPayment": {authorization, signature}}`. A quote changes nothing; the pass keeps paying until a refund is
     sent or a withdrawal starts. A requote (`requoted: true`): nothing sent, no withdrawal; sign the new one.
   - Seller says retry (any answer with `retry_after_seconds`, such as `refund_too_soon`): `409` with the seller's
     `code`, `message`, `retry_after_seconds` and a `Retry-After` header; nothing starts.
   - Seller refuses (no `retry_after_seconds`): the service starts the escrow withdrawal. It pays your wallet after
     `withdrawDelay`; the service then finalizes it and pays the gas, with a finalize the pass key signed at the
     start (it pays only `payer`). Asked again meanwhile: `already: true` with `readyAt`. A gas payment posted
     meanwhile: `409 refund_not_sent` with `readyAt`. A withdrawal that cannot start: `withdrawal_gas_short`,
     `withdrawal_no_gas`, `nothing_left` or `withdrawal_failed`, with `message`, the chain error in `detail` and the
     seller's answer in `seller`. `POST /c/<token>/finalize` finalizes a due withdrawal now.
   - A pass sent home (refund sent, or withdrawal started) pays nothing and takes no top-up: `prepare`, `deposit` and
     `link` answer `409 pass_sent_home`. Fund a new pass.

The link is the key: whoever holds it can spend the pass at its seller, nothing else. The service stores no key and
no secret that derives one.
