# Selling with ZEAM :: Pass

A gate, a paywall or both in front of your API, MCP server or WordPress site. No OAuth, no sign-up, no account with
us. Agents get in with a key. Install the plugin; connect your payout wallet.

- **Gate:** admits the keys you list. Each call is signed with the agent's key as a zero-value x402 payment: nothing
  is paid, the key needs no funds or ETH, nothing goes on chain. A listed key can sign a grant that admits one other
  key, for one tool or all, until a set time.
- **Paywall:** agents pay per call in USDC, from a wallet or a pass. You keep 90.01% of every sale. A failed call is
  not charged.
- **Both:** listed keys, paid per call.

The engine runs in your process with your keys. ZEAM holds none of your money or keys and makes no admission
decisions.

## Set up

1. **Install the plugin:** Node `@zeam-labs/pass`, Python `zeam-pass`, WordPress `ZEAM Pass`: `npm install @zeam-labs/pass`
   (Node ≥18) / `pip install zeam-pass` (Python ≥3.9) / download https://zeampass.com/downloads/zeam-pass-1.0.6.zip and use
   Plugins → Add New → Upload Plugin.
2. **Connect your payout wallet.** Your earnings go there. It admits no one; the gate admits the keys you list.
3. **Choose** gate, paywall or both. Paywall: set a price per call. Gate: list the keys you admit.

Node takes these options or reads ./pass.json; Python takes them as keyword arguments to `Pass(...)`; WordPress has
a settings page.

```json
{ "name": "acme", "site": "https://acme.example", "mode": "paywall", "price": "0.02",
  "payout": "0xYourWalletAddress" }
```

The agent side: [docs/AGENTS.md](../../docs/AGENTS.md).

## Serving agents

Your site serves your tools:

- **MCP** at `/mcp`; payment rides in the tool call's `_meta`.
- **HTTP** at `POST /v1/<tool>`, with the x402 headers, and `/openapi.json`.

Arguments are checked before any charge; an unpaid call with no arguments, as x402 directories send to read the
price, gets the 402 with your terms. The 402 carries the x402 `bazaar` discovery extension: how to call the tool,
its input schema and its output schema, so x402 directories can list it. The plugin verifies the payment, holds it while your tool runs, and settles
it only if the tool succeeds, whether or not the agent is still connected to receive the answer.

Prices per tool:

| Kind | Set | The agent pays |
|---|---|---|
| per call | `price` (the default), or a tool's own price | that price per call |
| free | `free` | nothing; optional `freeLimit`: calls an hour per address, then 429 |
| usage | a tool's `price` and `unit`; the tool reports units | units × `unit`, at most `price` (reserved per call) |
| time | `time: { block: "0.00025", blockMs: 250 }`; a tool's `meter: "time"` | blocks bought with `buy_time`, burned while calls run on a line; unburned time refunded |

The 402, `tools/list` (`_meta["zeam-pass/price"]`) and `/openapi.json` (`x-price`) show each tool's price.

Time: the plugin adds `buy_time` and `line`. An agent buys blocks, opens a line with its payer key, and calls time
tools on it with no payment per call. Time burns while a call runs; a call stops when the time runs out. Time is
credited only after the payment settles; you are paid only for time that burned; unburned time goes back with the
agent's refund. Time state is in your state directory (`meter/`). Detail: [docs/AGENTS.md](../../docs/AGENTS.md),
section 2.

## Money

- **Split.** Earnings go to your split contract: no owner, ZEAM included; no one can change it. It pays 90.01% to
  your wallet and 9.99% to ZEAM.
- **Gas.** Paying needs no ETH. ZEAM's relay sends the transactions your Pass signs and pays their gas when the
  transaction covers its gas at the 9.99% fee. The relay sends only what your side signed, to where it signed it.
- **Payouts.** Automatic once a payout covers its gas. The first payout waits for about $0.10: it also creates your
  split.
- **Deposits.** An agent's first call on a channel deposits at least the deposit floor (about $0.03; it tracks gas,
  [docs/ENGINE.md](../../docs/ENGINE.md)); later calls spend it.
- **Refunds.** The unspent balance goes back to the agent through the relay. ZEAM pays the gas when the channel's
  fees cover its deposit and refund gas; otherwise the agent pays the quoted gas in USDC (15% margin, priced by the
  relay, public at `https://api.zeampass.com/relay/quote`). Nothing is claimed for you from it. `selfSend`: the
  agent gets a signed refund of the full balance and sends it with ETH on Base for gas. Without you:
  `initiateWithdraw`, then `finalizeWithdraw` after the withdrawal delay, each with ETH on Base for gas. 1 refund per
  channel per hour. Detail: [docs/AGENTS.md](../../docs/AGENTS.md), section 5.
- **Stock x402 clients.** Stock x402 clients deposit 5x your price. They work when your price is at least 1/5 of the
  deposit floor in your 402 `deposit` line. Example: floor $0.03, price $0.006 and up.

## The gate

- **Admitting keys.** List the addresses of the keys you admit: `admit` in Node (`pass.json` or `pass({ admit })`)
  and Python (`Pass(admit=[...])`); "Admitted keys" on the WordPress settings page.

  ```json
  { "name": "acme", "mode": "gate", "payout": "0xYourWalletAddress",
    "admit": ["0xAgentKeyAddress", "0xAnotherAgentKeyAddress"] }
  ```

- **Contact.** A key not on your list is refused with a `how` that says to ask you. Set `contact` to an `https://`
  page or a `mailto:` address: Node `pass({ contact })` or `pass.json`, Python `Pass(contact=...)`, WordPress
  "Contact for access". It goes in every 402, in that refusal and in your `openapi.json`. WordPress defaults to your
  site's address and never shows your admin email.
- **What an agent sends.** A zero-value x402 `exact` payment signed with its key, on every call (any x402 client
  makes one). It moves nothing and is never sent to the chain. Your Pass verifies the signature in your process,
  refuses a replay, and admits the key if its address is on your list. Agent side:
  [docs/AGENTS.md](../../docs/AGENTS.md), section 3.
- **Grants.** A listed key can admit one other key: it signs a grant naming that key, one tool (or `*`) and an end
  time; the agent sends the grant as the `x-grant` header. A call signed by any other key is refused `bad_grant`.
  Remove the signing key from your list and every grant it signed ends.

  Node:

  ```js
  import { signGrant } from '@zeam-labs/pass'

  const grant = await signGrant({ key: process.env.ADMITTED_KEY, delegate: '0xTheirKeyAddress', scope: 'search', until: '2026-12-31T00:00:00Z' })
  ```

  Python:

  ```python
  from zeam_pass import sign_grant

  grant = sign_grant(os.environ["ADMITTED_KEY"], "0xTheirKeyAddress", "2026-12-31T00:00:00Z", scope="search")
  ```

  `key`: the private key of a listed address. `scope`: default `*`. `until`: an ISO time, a `Date` or a
  timezone-aware `datetime`. Both return the `x-grant` value for the agent. WordPress: the Grants box on the settings
  page signs with your browser wallet when it holds a listed key.

  The grant is base64url JSON `{delegate, scope, until, signature}`; `signature` is the admitted key's EIP-191
  signature of:

  ```
  ZEAM Pass grant
  delegate: <the key's address, lowercase>
  scope: <tool, or *>
  until: <ISO time>
  ```

  Without `scope` the signed line is `scope: self`, which covers every tool.
- **Price.**
  - 10,000 checks a month are free.
  - Your Pass makes a credit wallet on first start; `status()` (WordPress: the settings page) shows its address. It
    holds the gate's USDC on Base and needs no ETH. Node: `keys: { credit }` sets your own.
  - Credit is 2,000 checks at a time for $1 USDC ($0.50 per 1,000), bought after the month's 10,000 free checks are
    used and again below 200 remaining. Keep $1 USDC on Base in the credit wallet.
  - Credit at 0: refuse (default), or with `"onEmpty": "allow"`, admit and warn.
- **Both mode:** paid calls are not gate checks. `both` mode needs no USDC in the credit wallet.

## Status

`status()` (WordPress: the settings page) answers:

- `unclaimed`: `microUSD` paid to you and not yet claimed from the escrow, over `channels` channels.
- `claimedNotPaid`: `microUSD` claimed and not yet paid to your wallet. `splitDeployed` is false until your first
  payout creates your split.
- `lastPayout`: when, how much, which transactions. `lastTick`: the last background run.
- `gate`: this `month`'s `used` checks, the `free` allowance, `credit` left, `onEmpty`, and `unpaidAt` when calls
  were admitted with no credit.
- `creditWallet`: its `address` and `usdcMicro` balance.
- `chainError`: the chain read for the figures above failed.

**Payouts below break-even.** The relay pays out when ZEAM's 9.99% of the payout covers its gas; you keep 90.01%. A
payout (claim, settle, distribute, withdraw) goes out as one Multicall3 transaction in that order, all or nothing.
Below break-even, `unclaimed` or `claimedNotPaid` stays above 0, `lastTick.claims.USDC.waiting` reads "below
break-even: waits until the fee share covers the gas", and `payout()` (Python: `payout_now()`) answers
`paidBy: "you"` with `valueUSD`, `gasUSD` and a `transaction` to send from your wallet with ETH on Base for gas.

## What ZEAM runs

- **The relay:** sends signed transactions for Pass splits that cover their own gas.
- **The credit service:** sells gate checks.

Neither holds anything of yours.
