# The Pass engine

Every plugin (WordPress/PHP, Node, Python) runs the same engine in the seller's process. The seller's keys, channel
records, gate usage and settlement stay with the seller. ZEAM runs the relay (gas for signed transactions that pay
for themselves) and the credit service (gate checks).

Reference: the PHP engine in `wordpress/zeam-pass/lib/src/`, matching the x402 SDK. Node and Python follow its
structure, names and behavior, and pass the same shared fixtures.

## Pieces

| Piece | PHP reference | Job |
|---|---|---|
| Config | `Settlement/Config.php` | name, site, mode (gate/paywall/both), price (micro-USD per call), payout; the split (900100/99900 to the ZEAM fee address; with `payoutIsFeeRecipient`, payout = the fee address: 1000000 to it, fee share 1) and its salt (`keccak256("ZEAM Pass seller <name>")`); the predicted split receiver; the settle key (receiverAuthorizer); relay, credits and RPC URLs; withdraw delay 86400; USDC on Base |
| Store | `Settlement/Store.php`, `Gate/Store.php` | `get`, atomic `update(id, fn)`, `list`; channel records in the SDK's field layout plus `handedOver`, `depositsWePaid`, `repairedAt` |
| Server | `Settlement/Server.php`, `Verify.php` | `paymentRequired(resource)`, `verifyAndHold(header, resource)`, `settle(hold)`, `release(hold)` |
| Claims | `Settlement/Claims.php` | periodic: claim at margin with the payout in one relay request; the claim's gas (base and per voucher) counts with the payout's; a withdrawing channel's claim at its own margin |
| Refunds | `Settlement/Refunds.php` | see [Refunds](#refunds) |
| Withdrawals | `Settlement/Withdrawals.php` | periodic: stamp channels whose payer started a withdrawal; clear on cancel |
| Relay client | `Settlement/Relay.php` | `POST <relay>/relay {calls:[{to,data}], split:{split, salt, receiver}}` → `results[i].hash / error`. The relay sends 1 transaction at a time from its wallet. A request with a payout (settle, split creation, distribute, withdraw) goes out as one Multicall3 `aggregate3` in the given order, none allowed to fail. A refund the relay did not send leaves the channel's hour free |
| Gate | `Gate/Admission.php`, `Exact.php`, `Meter.php`, `Credits.php` | admission by key: a zero-value x402 `exact` signature verified offline; replay by from + lowercased nonce; grants from an admitted key to one delegate key, scoped to a tool; 10,000 free checks a month; credit notes signed by ZEAM's issuer; credit bought with an x402 `exact` payment from the credit wallet |
| Grant helpers | Node `signGrant` (`node/src/grant.mjs`); Python `sign_grant`, `gate_proof`, `new_key`, `key_address` (`python/zeam_pass/grants.py`); the WordPress settings page's Grants box | sign an `x-grant` with an admitted key; Python also signs an agent's zero-value proof |

## Request flow

1. Validate the arguments against the tool's `inputSchema`. Bad arguments: 400, nothing held. Except a probe: no
   payment, no line credential on a time tool, and no arguments (no body, a blank body, `{}`; MCP `arguments`
   absent, null or `{}`) to a paid tool answers the unpaid 402 of step 2, nothing run (a price that cannot be read
   for it: the 400).
2. By mode:
   - **paywall:** no payment → 402 from `paymentRequired`. Else `verifyAndHold`, run the tool, then `settle` on
     success (PAYMENT-RESPONSE / MCP `_meta["x402/payment-response"]`) or `release` on failure. A failed settle
     delivers nothing.
   - **gate:** `Admission.admit` with PAYMENT-SIGNATURE and `x-grant`, then `Meter.charge`, then run. The proof is a
     zero-value `exact` payment signed by the agent's key: nothing is paid, the key needs no funds, nothing goes on
     chain. A 402 refusal carries the zero-amount terms (PAYMENT-REQUIRED with `error`; `how` in the body). A key not
     admitted: 403. Meter out of checks: 503 `gate_credit_exhausted`.
   - **both:** the paywall payer (payerAuthorizer, else payer) must be admitted before anything is held; paid calls
     are not gate checks. The unpaid 402 adds an `admission` field. A payer not admitted: 403 `refused` with `how`.
     An answer through a grant carries `X-Pass-Granted-By: <admitting key's address>` (MCP:
     `_meta["zeam-pass/granted-by"]`), in gate mode too.

Prices: a tool's own `price`/`unit`, else the seller's `prices[tool]` (or `prices(tool, args)`), else `price`. The
402's `accepts[].amount` and `pricing` are the call's; `prices` tags every tool (`tools/list`
`_meta["zeam-pass/price"]`, OpenAPI `x-price`). Free tools skip payment and admission; `freeLimit` counts calls per
(tool, client address, clock hour), 429 `free_limit` over it; the count lives in `<stateDir>/free.json` (Node, Python)
or WordPress transients, so it survives a restart; a host that passes no client address gives one shared count. Usage: the voucher signs charged + reserve; the tool
reports whole units; settle commits min(reserve, units × unit). Every settle states `extra.chargedAmount` and `extra.reservedAmount`.
No units reported: released, 500 `tool_failed`. Shared: `wordpress/tests/pricing/fixtures.json`.

Time (`time: {block, blockMs, idleMs, maxBlocks}`, paywall or both): a clock per channel in `meter/clocks/<channelId>`
(`balanceMs`, `spentMs`, `returnedMs`, `on`, `since`, `lastActive`, `calls`, `credited`, `nonces`, `lines`), line
credentials by sha256 in `meter/lines/`. `buy_time` is priced blocks × block and credits blocks × blockMs once per hold,
before the settle: a credit that cannot be written releases the hold (500 `tool_failed`, nothing charged); a settle
that fails takes the credit back (`unbuy`). A line: a nonce (300 s, one attempt) signed EIP-191 over `ZEAM Pass line\nchannel:
<id>\nnonce: <nonce>` by the payer or payerAuthorizer. Burn = the union of running calls (each to its deadline =
start + balance) and `idleMs` after the last activity, while on. A call that ends in time burns from its start being
written to its handler returning (`end` gets both); the store's time before and after is not burned. Claims, payouts and refunds use earned = max(charged −
ceil(unburned ms × block / blockMs), claimed); a refund switches the meter off first, is refused while a line call
runs (`request_open`), and zeroes the clock and closes its lines once sent or handed over. A time tool without a
line: a paid call bounded to price × blockMs / block ms. Shared: `wordpress/tests/meter/fixtures.json`.

Bad arguments or a non-JSON body: 400 `invalid_arguments` in every engine. A result that is not valid JSON (a
non-finite number, non-UTF-8 bytes, a value the encoder changes or drops) is a failed call: hold released, 500
`tool_failed`, the same message in every engine.

A seller's `contact` (an `https://` URL or a `mailto:` address; WordPress defaults to the site's address) goes in
every 402 (body and PAYMENT-REQUIRED), in the 403 `refused` body and its `how`, and in `openapi.json` as
`info.contact`. Unset: none of these change.

## Refunds

- Proof: payer-signed; always sent through the relay.
- Free when ⌊(on-chain claimed + bundled claim) × 0.0999⌋ micro-USD ≥ ⌈(deposit + refund + riding claim) gas ×
  1.15⌉ micro-USD, both at the gas price read for the refund. The relay applies the same comparison.
- Otherwise 409 `refund_quote` (`gas_payment_needed`, with `feeMicroUSD`, `coverMicroUSD`, `gasUnits`,
  `gasUnitsWithMargin`, `l1FeeWei`, `gasPriceWei`, `ethUSD`, `marginPercent`, `quotedBy`): an EIP-3009
  `TransferWithAuthorization` of the quoted gas in the channel's token.
- Quote: the engine signs the refund and posts it to the relay's `POST /quote` (`{call: {to, data}, payment: true}`).
  The relay answers the gas the refund uses (`eth_simulateV1` from its sender; `eth_estimateGas` where the RPC lacks
  it, which over-reads a refund of the whole balance by its storage refund) + the gas a payment adds over that (median
  of its paid refunds, kept apart for simulated and estimated legs), Base's L1 data fee, gas and ETH price, `costMicroUSD` and `quoteMicroUSD` (cost × 1.15). The engine
  quotes `quoteMicroUSD` and checks the payment against `costMicroUSD` (`quotedBy: "relay"`). No valid relay quote:
  (refund + riding claim + payment + L1 fee in gas) × 1.15 (`quotedBy: "engine"`).
- Payment: to the relay's gas wallet (its `/health` `sender`), valid 300 s, posted back as `gasPayment`. Checks:
  from the payer; to that wallet; ≥ the send cost now (the quote's cost, no margin); ≤ the refund; in its window;
  signed by the payer. Sent as one Multicall3 `aggregate3`: the refund (or the escrow multicall of claim and
  refund), then the payment, neither allowed to fail.
- The relay sends when the payment covers the cost of that send: its `eth_estimateGas` + L1 data fee
  (`GasPriceOracle.getL1Fee`) at the current gas and ETH price. Else `code: "underpaid"` with that cost as
  `gasMicroUSD`; the engine answers a new 409 `refund_quote` (`requoted: true`, `sendCostMicroUSD`) for max(its
  fresh quote, cost × 1.15), with new typed data. Nothing is sent; the hour is untouched.
- Gas ≥ balance: `nothing_to_return` (`leftMicroUSD` stays in the channel, `returnedMicroUSD` 0).
- `selfSend: true`: a signed handover of the full balance, reserved and re-issued while the refund nonce is unchanged.
- A channel with no record (funded, no calls) is adopted from the `channelConfig` the payer sends when it hashes to
  the id, names this receiver and `receiverAuthorizer`, and holds a balance on chain. A config that fails a check is
  400 `channel_config_mismatch` for any channel, with `mismatch` = `fields`, `channelId`, `receiver`,
  `receiverAuthorizer` or `token`.
- 1 refund per channel per hour (`lastRefundAt`, set once the relay sends; `refund_too_soon` with
  `retry_after_seconds`).
- The gas payment's typed data is `transferAuthorizationTypedData`, the same bytes in every engine
  (`wordpress/tests/vectors.json`).

## Constants

| Name | Value |
|---|---|
| Fee share | 0.0999 (the seller keeps 90.01%); 1 with `payoutIsFeeRecipient` |
| Relay rule | sends only for an owner-less split paying the ZEAM fee address ≥ 99900 of 1000000, no distribution incentive; break-even at that split's fee share |
| Split | 900100 seller / 99900 ZEAM |
| Deposit floor | max(price, ceil(gas(145,000) × 1.25 / 0.0999)), from `eth_gasPrice` and the relay's `/health` `wethUSD` |
| Gas estimates | deposit 145k; claim 35k + 45k per voucher; payout 210k per asset (settle 60k, distribute 90k, withdraw 60k) + 280k when the split is created; refund 97k; a claim riding a refund 37k (37.7k in a paid refund); Multicall3 wrapping + `transferWithAuthorization` of a gas payment 58.6k; L1 data fee of a paid refund, in gas at the measured gas price, 13.8k, + 4.2k with a riding claim. The refund-payment units apply only when the relay's `/quote` does not answer |
| Refund gas, measured | calibrated 2026-09-29 from the receipts of the relay's 35 paid refunds (`0xB3ED726D24AF7C3ffbf04219A78506170155641b` to Multicall3, Base blocks 51915440 to 51949669; `seller/test/fixtures/paid-refunds-2026-09-29.json`). Refund + payment, 25 sends of 1,252 bytes: 155,101–156,013 gas (median 155,625). With a riding claim, 10 sends of 2,212 bytes: 193,256–194,092 (median 193,332). `eth_estimateGas` of the refund leg alone at each parent block: medians 96,187 and 133,294; a payment adds 59,430 and 60,035. The refund leg's gasUsed by `eth_simulateV1` (71 paid refunds to block 51957700, 3 of them whole-balance refunds from the audit of 2026-09-29, where `eth_estimateGas` reads 100,136 for a leg that uses 94,690): a payment adds 60,367–60,411 (60,391) alone and 61,111–61,155 (61,135) with a claim, the relay's `paymentOverUsed` defaults; its POSTed quotes come to 1.15–1.18 × the send cost on all 38 fixture receipts. L1 data fee: 8% of the cost (medians 13,830 and 18,059 gas at each send's gas price). The relay keeps its own medians from its receipts; engines and relay keep the calibration as `GAS_MEASURED` beside `GAS` |
| Buffer | 1.15 |
| Idle before claiming | 20 s |
| Hold lifetime | the payment's maxTimeoutSeconds (240) |
| ZEAM fee address | `0xc60996007B7657DE2F39fA0577E97d7aAF3b7d1e` (development override allowed) |
| Credit issuer | `0x4202d8042d89cbEFD9D48fE7f7Aa70061CC9Df22` (development override allowed) |
| Relay | `https://api.zeampass.com/relay` |
| Credit service | `https://api.zeampass.com/credits` |
| Gate credit | Gate mode: 2,000 checks at a time for $1 USDC ($0.50 per 1,000), bought after the month's 10,000 free checks are used and again below 200 remaining. Keep $1 USDC on Base in the credit wallet. `both` mode needs no USDC in the credit wallet: paid calls are not gate checks. |

## Shared fixtures

- `wordpress/tests/vectors.json`: crypto, typed data, ABI, channel ids, splits, grants and notes.
- `wordpress/tests/settlement/fixtures.json`: SDK client payloads with the SDK server's verdicts and responses.
- `wordpress/tests/gate/fixtures.json`: admission, grants, notes and credit purchase.
- `wordpress/tests/pricing/fixtures.json`: prices, usage charges, price tags, the pricing text and the free limit.
- `wordpress/tests/meter/fixtures.json`: the clock (buy, switch, begin, end, idle, unburned, forget), time options,
  the `time` terms text and the line message.

Every engine passes all five.
