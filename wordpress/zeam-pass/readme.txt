=== ZEAM Pass ===
Contributors: skimzey
Tags: ai, agents, x402, paywall, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A gate, a paywall or both in front of your posts for AI agents. No OAuth, no sign-up, no account with us.

== Description ==

AI agents search and read your posts on your site, over MCP and x402 HTTP, with two tools: search_posts and read_post.

* MCP: `/wp-json/zeam-pass/mcp`
* HTTP: `POST /wp-json/zeam-pass/v1/<tool>`
* OpenAPI: `/wp-json/zeam-pass/openapi.json`

Install the plugin, connect your payout wallet, choose a mode.

* **Paywall:** agents pay per call in USDC. Your site verifies the payment, holds it while the tool runs, and settles it only if the tool succeeds. Bad arguments, a missing post or a failed tool cost the agent $0. You keep 90.01% of every sale.
* **Gate:** admits the keys you list. An agent signs each call with its key as a zero-value x402 payment (any x402 client makes one): nothing is paid, the key needs no funds or ETH, nothing goes on chain. A listed key can sign a grant that admits one other key, for one tool or all, until a set time. The settings page signs grants with your browser wallet.
* **Both:** listed keys, paid per call.

The engine runs inside your WordPress. ZEAM holds none of your money or keys and makes no admission decisions.

* **Split.** Payments go to your split contract: no owner, ZEAM included; no one can change it. It pays 90.01% to your wallet and 9.99% to ZEAM. Your first payout creates it.
* **Keys.** On activation the plugin makes a settle key (and, for a gate, a credit wallet, which holds the gate's USDC), encrypted in your database. They never leave your server.
* **Gas.** Paying needs no ETH. ZEAM's relay sends the transactions your site signed and pays their gas when the transaction covers its gas at the 9.99% fee. It sends only what your site signed, to where your site signed it.
* **Payouts** run once a payout covers its gas. "Pay out now" pays through the relay when it covers its gas; below that it returns a transaction to send from your wallet with ETH on Base for gas.
* **Refunds.** An agent's unspent balance goes back through `/wp-json/zeam-pass/refund`. `selfSend` gives the agent a signed refund of the full balance, which it sends with ETH on Base for gas. Without you: `initiateWithdraw`, then `finalizeWithdraw` after the withdrawal delay, each with ETH on Base for gas.
* **Gate pricing.** Gate mode is a metered ZEAM service. 10,000 checks a month are free. After that, your site buys credit from the ZEAM credit service automatically, paid from its credit wallet, whose address the settings page shows: 2,000 checks at a time for $1 USDC ($0.50 per 1,000), bought after the month's 10,000 free checks are used and again below 200 remaining. Keep $1 USDC on Base in the credit wallet. It needs no ETH. Paywall mode uses no credit and has no limit. `both` mode needs no USDC in the credit wallet: paid calls are not gate checks.
* **When gate credit runs out, you choose.** "Refuse agents until credit is added" (the default) or "Admit agents and warn me here." Nothing else in the plugin stops or changes.
* **Prices per tool.** Each tool: its own price, else the price per call. A 402 carries that call's amount, `pricing` for it and `prices` for every tool. `tools/list`: `_meta["zeam-pass/price"]`. OpenAPI: `x-price`.
* **Free tools.** Check "Free" on a tool: no payment, no key, arguments still checked. "Free calls an hour per address": N per tool per IP per clock hour; over it a 429 with `Retry-After` and `retry_after_seconds`. Empty: no limit.
* **Usage.** A tool with a `unit` price reserves its price, reports units, and is charged units x unit, at most the reserve. No units reported: failed, nothing charged. The settle answer has `chargedAmount` and `reservedAmount`.
* **Line time.** Set a block price (and ms, default 250): adds `buy_time` (paid, blocks x block) and `line` (free). 1. `buy_time {blocks}`: credited to the paying channel with the payment; time that cannot be recorded is not charged. 2. `POST /wp-json/zeam-pass/line {"op":"open","channelId"}`, sign `sign` with the payer key, `{"op":"prove","channelId","nonce","signature"}`: a credential. 3. Call a time tool with header `x-line` (MCP: `_meta["zeam-pass/line"]`): no payment, time burns while it runs, `X-Pass-Ms-Remaining`, `X-Pass-Ms-Elapsed`. Out of time: 402 `out_of_time`. 4. A refund returns unburned time (`timeReturnedMs`) and closes the line. You are paid only for time that burned.
* Optional: hold the full text back from the WordPress REST API and your RSS and Atom feeds; anonymous readers get the excerpt and a pointer to your tools. Your pages and logged-in editors are unaffected.

== Installation ==

1. Download the plugin: https://zeampass.com/downloads/zeam-pass-1.0.5.zip (its SHA-256 is next to it, at the same address plus .sha256). Plugins -> Add New -> Upload Plugin, choose the zip, Install Now, then Activate.
2. Settings -> ZEAM Pass.
3. Connect your payout wallet: your browser wallet, or paste the address.
4. Choose gate, paywall or both. Paywall: set a price per call; optional, a price or Free per tool and free calls an hour per address. Gate: list the addresses of the keys you admit. "Contact for access": a web address or a mailto: address where an agent asks to be admitted. Agents see it in every 402 and when a key is refused. Empty: your site's address; your admin email is never shown.
5. Gate, optional: the Grants box signs a grant with your browser wallet, when it holds a listed key.
6. Save. The page shows your split, earnings, gate usage and the credit wallet to fund.

Your site must be reachable from the internet and use pretty permalinks.

**Updates.** New versions appear in Dashboard -> Updates and on the Plugins page like any other plugin, with the WordPress auto-update setting, off unless you turn it on. They come from zeampass.com, signed by ZEAM Labs: the plugin installs a new version only when its release file carries a valid signature and the download matches the signed SHA-256. A download that does not match is refused and nothing changes.

**Requirements:** PHP 7.4 or later with GMP or BCMath, and sodium. The plugin checks for them and names a missing one on every admin page; until then your tools answer that the site is not ready, and nothing is charged.

**Background work.** Every minute the plugin claims agent payments, pays out at margin, stamps withdrawing channels and buys gate credit. WordPress runs scheduled work on visits: on a low-traffic site, have a cron job request `https://your.site/wp-cron.php` every minute and set `define('DISABLE_WP_CRON', true);` in wp-config.php. "Run now" on the settings page runs it at once.

**Keys.** Add an encryption key of at least 32 random characters to wp-config.php: `define('ZEAM_PASS_KEY', '…');`. Without it the plugin derives one from your WordPress salts and says so on its settings page. Keep the value: without it the stored keys cannot be opened.

**More tools.** Add a tool with the `zeam_pass_tools` filter. Name: a-z, 0-9 and _. `run` gets the arguments and a meter and returns an array or a `WP_Error`:

`add_filter('zeam_pass_tools', function ($tools) { $tools['word_count'] = ['description' => 'Counts words.', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']], 'price' => '0.05', 'unit' => '0.0001', 'run' => function ($args, $meter) { $n = str_word_count($args['text']); $meter->units($n); return ['words' => $n]; }]; return $tools; });`

Keys: `description`, `inputSchema`, `price` (USD, optional), `unit` (USD per unit, optional), `free` (optional), `meter` (`'time'`, optional), `run`. A `'meter' => 'time'` tool needs line time, is never free, and gets its deadline from `$meter->deadlineMs()` (epoch ms): on a line it spends the line's time; without one it is a paid call for price x block ms / block, and past that it answers 402 `out_of_time`, not charged. The names `buy_time` and `line` are taken. A price that is not USD with up to 6 decimals, $0.000001 to $1,000, drops the tool. `zeam_pass_prices` filter, optional: `($spec, $tool, $args)` returns a price for tools with none of their own; a price it cannot give answers 500 `price_invalid`.

**Advanced.** The settings page sets the relay (default `https://api.zeampass.com/relay`), the credit service (default `https://api.zeampass.com/credits`) and the Base RPC (default `https://mainnet.base.org`, rate-limited). Development only: `ZEAM_PASS_FEE_RECIPIENT` and `ZEAM_PASS_CREDIT_ISSUER` in wp-config.php override the fee address and the credit issuer.

== External services ==

The plugin calls four outside services, with only the data listed here.

* **Base RPC** (default `https://mainnet.base.org`, run by Coinbase; configurable). Reads the gas price, the agents' channels in the x402 escrow, and your split's balance. It receives the public addresses and channel ids it is asked about. [Terms](https://docs.base.org/terms-of-service), [privacy](https://docs.base.org/privacy-policy).
* **ZEAM relay** (default `https://api.zeampass.com/relay`, run by ZEAM Labs; configurable). Sends to Base the transactions your site signed: claims, payouts and refunds for your channels, and agents' deposits. It receives those signed transactions and your split's address and salt, pays their gas, and cannot change or redirect them. Paywall and both modes. [Terms](https://zeampass.com/terms), [privacy](https://zeampass.com/privacy).
* **ZEAM updates** (`https://zeampass.com/downloads/zeam-pass.json`, run by ZEAM Labs). At most every 12 hours, and when you view the update details, WordPress fetches this signed release file to see whether a new version exists, then downloads the new version from `https://zeampass.com/downloads/` when you or your auto-update setting update the plugin. Both requests are plain HTTPS GETs with the user agent `WordPress` and nothing else about your site: no site address, no WordPress or plugin version, no settings, no keys. [Terms](https://zeampass.com/terms), [privacy](https://zeampass.com/privacy).
* **ZEAM credit service** (default `https://api.zeampass.com/credits`, run by ZEAM Labs). Where gate mode buys checks after the 10,000 free each month. It receives your payout address, your Pass name, how many checks to buy, and the USDC payment from your credit wallet, and returns a credit note signed by ZEAM. Gate mode only. [Terms](https://zeampass.com/terms), [privacy](https://zeampass.com/privacy).

No personal data about your visitors or editors is sent. Agents' payments reach the relay only as the transactions they signed.

== Changelog ==

= 1.0.5 =
* An unpaid call with no arguments answers 402 with the price, as x402 directories expect when they probe. Arguments that are present are still checked before any payment.

= 1.0.4 =
* Updates from zeampass.com, signed.

= 1.0.3 =
* Distributed from zeampass.com only.

= 1.0.2 =
* Readme: gate pricing, the seller's choice when credit runs out, and the credit service stated plainly.

= 1.0.1 =
* Plugin Check clean: exception messages escaped, queries prepared with fixed table names, input unslashed and sanitized, the file stores used only by the tests left out of the plugin.

= 1.0.0 =
* Prices per tool, free tools, a free-call limit per address, usage pricing by unit.
* `zeam_pass_tools` and `zeam_pass_prices` filters.
* Line time: `buy_time`, `line`, `POST /wp-json/zeam-pass/line`, time tools; unburned time refunded.

= 0.6.0 =
* A refused key is told where to ask: your contact, or your site's address. Every 402 and the OpenAPI document carry it.
* A post that is not valid JSON (non-UTF-8 bytes) is a failed call: nothing charged.
* Outbound requests use the WordPress HTTP API.
* BCMath when GMP is missing.
* A deposit delivers only with a recorded charge.
* Every relay gas unit counts against the 9.99% margin.
* Refunds: ZEAM pays the gas when the channel's fees cover its deposit and refund gas; otherwise the buyer signs a gasless USDC payment of the quoted gas (15% margin) and the relay sends refund and payment in one transaction; a requote when gas moves past the margin. {"selfSend": true} returns a signed refund of the full balance.
* channelConfig refunds a channel with no calls; a mismatched one is refused (channel_config_mismatch). 1 refund per channel per hour, with retry_after_seconds.
* MCP errors carry the HTTP fields in structuredContent; in both mode PAYMENT-REQUIRED carries the admission field.
* Both mode: X-Pass-Granted-By (MCP: `_meta["zeam-pass/granted-by"]`) on granted answers; a refused key gets a `how`.
* The gate admits keys; the payout wallet admits no one.
* Grants box on the settings page.
* "Pay out now" shows its answer.
* A payment that does not verify gets the same error, code and reason over HTTP and MCP; a non-JSON body gets a 400.

= 0.5.0 =
* The engine runs inside WordPress: verify, hold, settle, claim, pay out and refund, with its own keys.
* Gate and both modes: admitted keys and grants (x-grant). Gate: 10,000 free checks a month, automatic credit purchase.
* Channels, gate usage and replay protection in the WordPress database, each read-modify-write under a database lock.
* Keys generated on activation, encrypted with libsodium.
* A one-minute schedule for claims, payouts, withdrawals and credit; "Run now".
* Pay out now: through the relay at margin, or as a transaction from your wallet.

= 0.3.0 =
* Hold, run, settle: a payment settles only after the tool succeeds.
* Arguments are checked against each tool's input schema before any payment.

= 0.2.0 =
* MCP and x402 HTTP tools, search_posts and read_post.
