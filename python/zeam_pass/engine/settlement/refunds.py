import math
import re
import secrets

from ..batch_settlement import BatchSettlement
from ..core import Address, Hex, Secp256k1
from ..messages import PassMessages
from ..php import php_empty, php_round, php_str, time_ms, to_int
from .channels import Channels, n
from .chain import Chain
from .gas_quote import GasQuote
from .relay import Relay
from .verify import Verify


_DIGITS = re.compile(r"[0-9]+")
_RETRY_IN = re.compile(r"retry in (\d+)s")


def _cut(text, length):
    return text.encode("utf-8")[:length].decode("utf-8", "ignore")


class Refunds:
    FUTURE_SKEW_MS = 30000

    def __init__(self, cfg, store, chain=None, relay=None):
        self.cfg = cfg
        self.store = store
        self.chain = chain or Chain(cfg.rpcUrl)
        self.relay = relay or Relay(cfg.relayUrl)
        self.gas = GasQuote(cfg, self.chain, self.relay)
        self.channels = Channels(cfg, store, self.chain)

    @staticmethod
    def message(channel_id, issued):
        return PassMessages.REALM + " refund\nchannel: " + php_str(channel_id).lower() + "\nissued: " + php_str(issued)

    @staticmethod
    def _answer(status, body):
        return {"status": status, "body": body}

    @staticmethod
    def _failed(status, code, why, more=None):
        return Refunds._answer(status, {"op": "refund_failed", "code": code, "why": why, **(more or {})})

    def proof_owner(self, channel_id, issued, signature):
        t = time_ms(issued.strip()) if isinstance(issued, str) and issued.strip() != "" else None
        if t is None:
            return {"ok": False, "why": "issued is not a timestamp"}
        now = self.cfg.now_ms()
        window = 1000 * int(self.cfg.refundWindowSecs)
        if now - t > window or t - now > self.FUTURE_SKEW_MS:
            return {"ok": False, "why": f"issued {issued} is outside the {int(self.cfg.refundWindowSecs)}s window"}
        try:
            who = Secp256k1.recover_personal(Refunds.message(channel_id, issued), php_str(signature))
        except Exception as e:
            return {"ok": False, "why": f"signature does not recover: {e}"}
        return {"ok": True, "address": who.lower()}

    @staticmethod
    def owns(channel, address):
        who = php_str(address).lower()
        if who == "":
            return False
        config = channel.get("channelConfig") if isinstance(channel.get("channelConfig"), dict) else {}
        payer = php_str(config.get("payer")).lower()
        auth = php_str(config.get("payerAuthorizer")).lower()
        return who == payer or (auth != "" and who == auth)

    def handle(self, channel_id, issued, signature, ask=None):
        cid = php_str(channel_id).lower()
        ch = self.channels.repaired(cid) if Verify.is_canonical_channel_id(cid) else None
        sent = (ask or {}).get("channelConfig")
        own = None if sent is None else self._own_config(cid, sent)
        if own is not None and own.get("mismatch") is not None:
            return Refunds._failed(400, "channel_config_mismatch", own["why"], {"mismatch": own["mismatch"]})
        config = own.get("config") if own is not None else None
        if ch is None and config is None:
            return Refunds._failed(404, "unknown_channel", "no channel with that id here. For a channel with no calls here, send its channelConfig with the proof.")
        proof = self.proof_owner(cid, issued, signature)
        if not proof["ok"]:
            return Refunds._failed(400, "proof_invalid", proof["why"], {"sign": Refunds.message(cid, "<ISO8601 within five minutes>")})
        if not Refunds.owns(ch if ch is not None else {"channelConfig": config}, proof["address"]):
            return Refunds._failed(403, "not_the_payer", proof["address"] + " is not this channel's payer")
        if ch is None:
            adopted = self._adopt(cid, config)
            if adopted.get("failed") is not None:
                return adopted["failed"]
            ch = adopted["channel"]
        return self._refund_now(ch, ask or {})

    def _own_config(self, cid, config):
        fields = {"mismatch": "fields", "why": "channelConfig needs payer, payerAuthorizer, receiver, receiverAuthorizer, token, withdrawDelay and salt, as deposited"}
        if not isinstance(config, dict):
            return fields
        try:
            computed = Verify.compute_channel_id(config, self.cfg.network).lower()
        except Exception:
            return fields
        if computed != cid:
            return {"mismatch": "channelId", "why": "channelConfig hashes to " + computed + ", not to channelId " + cid}
        if not Address.equals(config.get("receiver"), self.cfg.receiver):
            return {"mismatch": "receiver", "why": "channelConfig receiver is " + php_str(config.get("receiver")).lower() + "; this seller's is " + php_str(self.cfg.receiver).lower()}
        if not Address.equals(config.get("receiverAuthorizer"), self.cfg.receiverAuthorizer):
            return {"mismatch": "receiverAuthorizer", "why": "channelConfig receiverAuthorizer is " + php_str(config.get("receiverAuthorizer")).lower() + "; this seller's is " + php_str(self.cfg.receiverAuthorizer).lower()}
        if self.cfg.asset_of(config.get("token")) is None:
            return {"mismatch": "token", "why": "channelConfig token " + php_str(config.get("token")).lower() + " is not accepted here"}
        return {"config": config}

    def _adopt(self, cid, config):
        try:
            st = self.chain.channel(cid)
        except Exception:
            return {"failed": Refunds._failed(409, "chain_unreadable", "could not read the channel on chain", {"retry_after_seconds": Refunds.RETRY_SECS})}
        if n(st["balance"]) <= 0:
            return {"failed": Refunds._failed(400, "channel_config_mismatch", "channelConfig names this seller, but that channel holds no balance on chain: its escrow balance is 0", {"mismatch": "balance"})}
        now = self.cfg.now_ms()

        def adopt(r):
            if r is not None:
                return r
            return {
                "channelId": cid,
                "channelConfig": config,
                "chargedCumulativeAmount": php_str(st["totalClaimed"]),
                "balance": php_str(st["balance"]),
                "totalClaimed": php_str(st["totalClaimed"]),
                "withdrawRequestedAt": 0,
                "refundNonce": 0,
                "onchainSyncedAt": now,
                "lastRequestTimestamp": 0,
                "adoptedAt": now,
            }

        return {"channel": self.channels.update(cid, adopt)}

    AUTHORIZATION_FIELDS = ("from", "to", "value", "validAfter", "validBefore", "nonce")
    GAS_PAYMENT_MARGIN_SECS = 30
    RETRY_SECS = 60
    REFUND_EVERY_SECS = 3600

    @staticmethod
    def ask(body):
        b = body if isinstance(body, dict) else {}

        def scalar(x):
            return php_str(x) if isinstance(x, (str, int)) and not isinstance(x, bool) else None

        p = b.get("gasPayment") if isinstance(b.get("gasPayment"), dict) else None
        a = p.get("authorization") if p is not None and isinstance(p.get("authorization"), dict) else None
        config = b.get("channelConfig")
        return {
            "selfSend": b.get("selfSend") is True or b.get("selfSend") == "true",
            "channelConfig": dict(config) if isinstance(config, dict) else config,
            "gasPayment": None if p is None else {
                "authorization": None if a is None else {k: scalar(a.get(k)) for k in Refunds.AUTHORIZATION_FIELDS},
                "signature": scalar(p.get("signature")),
            },
        }

    def _micro_usd(self, ch, units):
        return self.cfg.micro_usd_of(ch["channelConfig"]["token"], units)

    @staticmethod
    def usd(micro_usd):
        s = ("%.6f" % (micro_usd / 1e6)).rstrip("0").rstrip(".")
        return "0" if s == "" else s

    def _refund_now(self, raw, ask=None):
        ask = ask or {}
        ident = raw["channelId"].lower()
        ch = Channels.earned(self.cfg, raw)
        try:
            live = self.chain.live(ident)
        except Exception:
            return Refunds._failed(409, "chain_unreadable", "could not read the channel on chain", {"retry_after_seconds": Refunds.RETRY_SECS})
        left = Channels.refundable(ch, live)
        if n(left) <= 0:
            return Refunds._failed(409, "nothing_to_return", "nothing to return: the channel is fully spent",
                                   {"leftMicroUSD": 0, "returnedMicroUSD": 0, "gasMicroUSD": 0})
        now = self.cfg.now_ms()
        if Channels.pending_live(ch, now):
            wait = int(math.ceil((to_int(ch["pendingRequest"]["expiresAt"]) - now) / 1000))
            return Refunds._failed(409, "request_open", f"a paid call is open on this channel. Retry in {wait}s.", {"retry_after_seconds": wait})
        try:
            nonce = self.chain.refund_nonce(ident)
        except Exception:
            return Refunds._failed(409, "chain_unreadable", "could not read the refund nonce on chain", {"retry_after_seconds": Refunds.RETRY_SECS})
        if ask.get("selfSend") is True:
            f = self._frozen(raw, live, ch, left)
            return f["failed"] if "failed" in f else self._self_send(f["ch"], live, f["left"], nonce)
        since = now - to_int(ch.get("lastRefundAt") if ch.get("lastRefundAt") is not None else 0)
        if since < 1000 * Refunds.REFUND_EVERY_SECS:
            wait = int(math.ceil((1000 * Refunds.REFUND_EVERY_SECS - since) / 1000))
            return Refunds._failed(409, "refund_too_soon", f"1 refund per channel per hour. Retry in {wait}s, or send " + Refunds.SELF_SEND
                                   + " for a signed refund to send yourself.", {"retry_after_seconds": wait, "leftMicroUSD": self._micro_usd(ch, left)})
        f = self._frozen(raw, live, ch, left)
        if "failed" in f:
            return f["failed"]
        ch, left = f["ch"], f["left"]
        left_micro_usd = self._micro_usd(ch, left)
        try:
            call = self.refund_call(ch, live, left, nonce)
        except Exception as e:
            return Refunds._failed(409, "refund_error", _cut(str(e), 160), {"retry_after_seconds": Refunds.RETRY_SECS, "leftMicroUSD": left_micro_usd})
        gas = self.refund_gas(ch, live, call)
        if gas is None:
            return Refunds._failed(503, "gas_unpriced", "refund gas cannot be priced. Retry in 60s, or send "
                                   + Refunds.SELF_SEND + " for a signed refund to send yourself.",
                                   {"retry_after_seconds": Refunds.RETRY_SECS, "leftMicroUSD": left_micro_usd})
        amounts = {"leftMicroUSD": left_micro_usd, "returnedMicroUSD": left_micro_usd, "gasMicroUSD": gas["gasMicroUSD"],
                   "feeMicroUSD": gas["feeMicroUSD"], "coverMicroUSD": gas["coverMicroUSD"]}
        payment = None
        if not gas["covered"]:
            asset = self.cfg.asset_of(ch["channelConfig"]["token"])
            rule = "channel fees $" + Refunds.usd(gas["feeMicroUSD"]) + " < deposit and refund gas $" + Refunds.usd(gas["coverMicroUSD"])
            if asset is None or not asset.get("eip3009"):
                return Refunds._failed(409, "nothing_to_return", rule + "; this token has no gasless payment for the $"
                                       + Refunds.usd(gas["gasMicroUSD"]) + " refund gas. " + Refunds.SELF, {**amounts, "returnedMicroUSD": 0, **gas["detail"]})
            if gas["gasMicroUSD"] >= left_micro_usd:
                return Refunds._failed(409, "nothing_to_return", rule + "; refund gas $" + Refunds.usd(gas["gasMicroUSD"])
                                       + " >= balance $" + Refunds.usd(left_micro_usd) + ". " + Refunds.SELF,
                                       {**amounts, "returnedMicroUSD": 0, **gas["detail"]})
            pay_to = self.gas.gas_wallet()
            if pay_to is None:
                return Refunds._failed(503, "gas_unpriced", "the relay is unreachable. Retry in 60s, or send "
                                       + Refunds.SELF_SEND + " for a signed refund to send yourself.",
                                       {"retry_after_seconds": Refunds.RETRY_SECS, "leftMicroUSD": left_micro_usd})
            units = self.cfg.units_of_micro_usd(asset, gas["gasMicroUSD"])
            offered = ask.get("gasPayment")
            if offered is None:
                return self._gas_quote(ch, asset, pay_to, units, amounts, gas, None)
            bad = self.gas_payment_problem(ch, asset, pay_to, self.cfg.units_of_micro_usd(asset, gas["costMicroUSD"]), left, offered)
            if bad is not None:
                return self._gas_quote(ch, asset, pay_to, units, amounts, gas, bad)
            payment = {"asset": asset, "payTo": pay_to, "authorization": offered["authorization"], "signature": offered["signature"]}
        if payment is None:
            return self._send_refund(ch, call, left, 0, amounts)
        pay = {"to": payment["asset"]["address"], "data": BatchSettlement.encode_transfer_with_authorization(payment["authorization"], payment["signature"])}
        bundle = {"to": BatchSettlement.MULTICALL3, "data": BatchSettlement.encode_aggregate3([call, pay])}
        out = self._send_refund(ch, bundle, left, self._micro_usd(ch, payment["authorization"]["value"]), amounts)
        return out if "underpaid" not in out else self._requote(ch, live, payment, amounts, gas, out["underpaid"], call)

    def _frozen(self, raw, live, ch, left):
        meter = getattr(self.cfg, "meter", None)
        if meter is None:
            return {"ch": ch, "left": left}
        if not meter.stop(ch["channelId"]):
            return {"failed": Refunds._failed(409, "request_open", "a call is running on this channel's line. Retry in 5s.", {"retry_after_seconds": 5})}
        now = Channels.earned(self.cfg, raw)
        rest = Channels.refundable(now, live)
        if n(rest) <= 0:
            return {"failed": Refunds._failed(409, "nothing_to_return", "nothing to return: the channel is fully spent",
                                              {"leftMicroUSD": 0, "returnedMicroUSD": 0, "gasMicroUSD": 0})}
        return {"ch": now, "left": rest}

    def _requote(self, ch, live, payment, amounts, gas, send_cost, call):
        fresh = self.refund_gas(ch, live, call) or gas
        quoted = max(0 if fresh["covered"] else fresh["gasMicroUSD"], int(math.ceil(send_cost * self.cfg.gasBuffer)))
        numbers = {**amounts, "gasMicroUSD": quoted, "feeMicroUSD": fresh["feeMicroUSD"], "coverMicroUSD": fresh["coverMicroUSD"], "sendCostMicroUSD": send_cost}
        moved = ("refund gas rose past the quote margin: sending costs $" + Refunds.usd(send_cost)
                 + ", your payment is $" + Refunds.usd(self._micro_usd(ch, payment["authorization"]["value"])) + ". Nothing was sent")
        if quoted >= amounts["leftMicroUSD"]:
            return Refunds._failed(409, "nothing_to_return", moved + "; refund gas $" + Refunds.usd(quoted) + " (margin included) >= balance $"
                                   + Refunds.usd(amounts["leftMicroUSD"]) + ". " + Refunds.SELF,
                                   {**numbers, "returnedMicroUSD": 0, **fresh["detail"]})
        units = self.cfg.units_of_micro_usd(payment["asset"], quoted)
        return self._gas_quote(ch, payment["asset"], payment["payTo"], units, numbers, fresh,
                               moved + ". New quote: that cost + " + php_str(fresh["detail"]["marginPercent"]) + "% margin",
                               {"requoted": True, "sendCostMicroUSD": send_cost})

    SELF_SEND = '{"selfSend": true}'
    SELF = "Send " + SELF_SEND + " for a signed refund of the full balance, sent at your own gas."

    def _self_send(self, ch, live, left, nonce):
        handed = ch.get("handedOver") if isinstance(ch.get("handedOver"), dict) else {}
        if not php_empty(handed.get("transaction")) and php_str(handed.get("nonce")) == php_str(nonce):
            return Refunds._answer(200, {
                "op": "refund_signed",
                "microUSD": self._micro_usd(ch, handed["units"] if handed.get("units") is not None else "0"),
                "transaction": handed["transaction"],
                "why": "your signed refund is unsent; here it is again",
            })
        try:
            call = self.refund_call(ch, live, left, nonce)
        except Exception as e:
            return Refunds._failed(409, "refund_error", _cut(str(e), 160), {"retry_after_seconds": Refunds.RETRY_SECS})
        return self._hand_over(ch, live, call, left, nonce)

    def refund_gas(self, ch, live, call=None):
        bundled = Refunds.bundled_claim(ch, live)
        q = self.gas.refund(bundled > 0, True)
        if q is None:
            return None
        cover = q["cover"]
        earned = self._micro_usd(ch, str(n(live["claimed"]) + bundled))
        fee_micro_usd = GasQuote.fee_micro_usd(earned, self.cfg.feeShare)

        def detail_of(p):
            return {k: p[k] for k in ("gasUnits", "gasUnitsWithMargin", "gasPriceWei", "ethUSD", "l1FeeWei", "marginPercent", "quotedBy")}

        if fee_micro_usd >= cover["microUSD"]:
            return {"covered": True, "gasMicroUSD": 0, "costMicroUSD": 0, "feeMicroUSD": fee_micro_usd, "coverMicroUSD": cover["microUSD"], "detail": detail_of(q["pay"])}
        pay = self.gas.relay_quote(call) or q["pay"]
        return {"covered": False, "gasMicroUSD": pay["microUSD"], "costMicroUSD": pay["costMicroUSD"], "feeMicroUSD": fee_micro_usd, "coverMicroUSD": cover["microUSD"], "detail": detail_of(pay)}

    def gas_payment_problem(self, ch, asset, pay_to, units, left, p):
        a = p.get("authorization")
        if a is None or any(v is None for v in a.values()) or p.get("signature") is None:
            return "gasPayment is {authorization: {from, to, value, validAfter, validBefore, nonce}, signature}"
        a = {k: php_str(v) for k, v in a.items()}
        if not Address.equals(a["from"], ch["channelConfig"]["payer"]):
            return "gasPayment must come from the channel's payer"
        if not Address.equals(a["to"], pay_to):
            return "gasPayment must go to the relay gas wallet " + pay_to
        if not _DIGITS.fullmatch(a["value"]) or int(a["value"]) < int(units):
            return "gasPayment is " + a["value"] + "; sending costs " + php_str(units)
        if int(a["value"]) > n(left):
            return "gasPayment is " + a["value"] + ", over the " + php_str(left) + " refund"
        now_secs = self.cfg.now_ms() // 1000
        if not _DIGITS.fullmatch(a["validAfter"]) or int(a["validAfter"]) > now_secs:
            return "gasPayment is not valid yet"
        if not _DIGITS.fullmatch(a["validBefore"]) or int(a["validBefore"]) < now_secs + Refunds.GAS_PAYMENT_MARGIN_SECS:
            return "gasPayment expires too soon"
        if not Hex.is_hex(a["nonce"], 32):
            return "gasPayment nonce is not 32 bytes"
        try:
            signer = Secp256k1.recover_hash(BatchSettlement.transfer_authorization_digest(Refunds.token_domain(self.cfg, asset), a), p["signature"])
        except Exception:
            signer = None
        if signer is None or not Address.equals(signer, a["from"]):
            return "gasPayment signature is not the payer's"
        return None

    @staticmethod
    def token_domain(cfg, asset):
        return BatchSettlement.token_domain(asset["address"], asset["name"], asset["version"] if asset.get("version") is not None else "1", cfg.chainId)

    def _gas_quote(self, ch, asset, pay_to, units, amounts, gas_info, why, extra=None):
        now_secs = self.cfg.now_ms() // 1000
        secs = int(float(self.cfg.gasPaymentSecs))
        authorization = {
            "from": Address.checksum(ch["channelConfig"]["payer"]),
            "to": Address.checksum(pay_to),
            "value": php_str(units),
            "validAfter": "0",
            "validBefore": str(now_secs + secs),
            "nonce": "0x" + secrets.token_hex(32),
        }
        gas = self._micro_usd(ch, units)
        d = gas_info["detail"]
        lead = "" if why is None else why + ". "
        return Refunds._answer(409, {
            "op": "refund_quote",
            "code": "gas_payment_needed",
            "why": lead + "channel fees $" + Refunds.usd(gas_info["feeMicroUSD"]) + " < deposit and refund gas $"
                   + Refunds.usd(gas_info["coverMicroUSD"]) + ". Refund gas: $" + Refunds.usd(gas)
                   + " USDC, no ETH (" + php_str(d["gasUnits"]) + " gas, L1 fee " + php_str(d["l1FeeWei"]) + " wei, "
                   + php_str(d["gasPriceWei"]) + " wei per gas, $" + php_str(d["ethUSD"]) + " per ETH, " + php_str(d["marginPercent"]) + "% margin; "
                   + ("priced by the relay" if d["quotedBy"] == "relay" else "priced from measured refunds") + "). Sign authorization (EIP-3009 TransferWithAuthorization; typed data in sign) and POST again with "
                   + "gasPayment: {authorization, signature} within " + str(secs) + "s. The refund of $" + Refunds.usd(amounts["leftMicroUSD"])
                   + " and the gas payment go in one transaction: $" + Refunds.usd(amounts["leftMicroUSD"] - gas) + " net to you. Or send "
                   + Refunds.SELF_SEND + " for a signed refund of the full balance, sent at your own gas.",
            **amounts,
            "gasMicroUSD": gas,
            **d,
            **(extra or {}),
            "payTo": authorization["to"],
            "authorization": authorization,
            "sign": BatchSettlement.transfer_authorization_typed_data(Refunds.token_domain(self.cfg, asset), authorization),
        })

    @staticmethod
    def bundled_claim(ch, live):
        charged = n(ch.get("chargedCumulativeAmount", "0"))
        if charged <= n(live["claimed"]) or php_empty(ch.get("signature")) or php_empty(ch.get("signedMaxClaimable")):
            return 0
        return charged - n(live["claimed"])

    def refund_call(self, ch, live, left, nonce):
        ident = ch["channelId"].lower()
        refund_sig = BatchSettlement.sign_refund(self.cfg.signing_key(), ident, left, nonce, self.cfg.chainId)
        refund = BatchSettlement.encode_refund_with_signature(ch["channelConfig"], left, nonce, refund_sig)
        if Refunds.bundled_claim(ch, live) <= 0:
            return {"to": BatchSettlement.ESCROW, "data": refund}
        claims = [Channels.claim_entry(ch)]
        claim_sig = BatchSettlement.sign_claim_batch(self.cfg.signing_key(), claims, self.cfg.chainId)
        return {
            "to": BatchSettlement.ESCROW,
            "data": BatchSettlement.encode_multicall([BatchSettlement.encode_claim_with_signature(claims, claim_sig), refund]),
        }

    def _send_refund(self, ch, call, left, gas_micro_usd, amounts=None):
        amounts = amounts or {}
        ident = ch["channelId"].lower()
        sent = self.relay.send([call], self.cfg.relay_split())
        if "hash" not in sent[0]:
            error = php_str(sent[0].get("error") or "the relay did not send the refund")
            numbers = {k: v for k, v in (("leftMicroUSD", amounts.get("leftMicroUSD")), ("gasMicroUSD", gas_micro_usd),
                                         ("feeMicroUSD", amounts.get("feeMicroUSD")), ("coverMicroUSD", amounts.get("coverMicroUSD"))) if v is not None}
            if gas_micro_usd > 0 and sent[0].get("code") == "underpaid":
                return {"underpaid": max(1, to_int(sent[0].get("gasMicroUSD") or 0))}
            if "one refund an hour" in error:
                m = _RETRY_IN.search(error)
                wait = int(m.group(1)) if m else Refunds.REFUND_EVERY_SECS
                return Refunds._failed(409, "refund_too_soon", f"1 refund per channel per hour. Retry in {wait}s, or send "
                                       + Refunds.SELF_SEND + " for a signed refund to send yourself.", {"retry_after_seconds": wait, **numbers})
            return Refunds._failed(409, "refund_error", _cut(error, 160), {"retry_after_seconds": Refunds.RETRY_SECS, **numbers})
        after = None
        tries = int(self.cfg.drainTries)
        for i in range(max(1, tries)):
            try:
                after = self.chain.live(ident)
            except Exception:
                after = None
            if after is not None and n(after["balance"]) <= n(after["claimed"]):
                break
            if i + 1 < tries:
                self.cfg.sleep_ms(self.cfg.drainWaitMs)
        drained = after is not None and n(after["balance"]) <= n(after["claimed"])
        at = self.cfg.now_ms()
        state = None

        def follow(r):
            if r is None:
                return None
            r["lastRefundAt"] = at
            if after is not None:
                r["balance"] = after["balance"]
                r["totalClaimed"] = after["claimed"]
                r["chargedCumulativeAmount"] = after["claimed"]
                r["refundNonce"] = to_int(r.get("refundNonce", 0)) + 1
                for k in ("signedMaxClaimable", "signature", "pendingRequest", "handedOver"):
                    r.pop(k, None)
            elif n(ch.get("chargedCumulativeAmount", "0")) < n(r.get("chargedCumulativeAmount", "0")):
                r["chargedCumulativeAmount"] = ch["chargedCumulativeAmount"]
            return r

        self.channels.update(ident, follow)
        if after is not None:
            state = {"channelId": ident, "balance": after["balance"], "totalClaimed": after["claimed"], "chargedCumulativeAmount": after["claimed"]}
        time_ms = self._forget(ident)
        returned = self._micro_usd(ch, left)
        body = {"op": "refunded", "microUSD": returned, "returnedMicroUSD": returned, "gasMicroUSD": gas_micro_usd,
                "transaction": sent[0]["hash"], "drained": drained}
        if time_ms > 0:
            body["timeReturnedMs"] = time_ms
        if gas_micro_usd > 0:
            body["why"] = ("refund $" + Refunds.usd(returned) + " and gas payment $" + Refunds.usd(gas_micro_usd)
                           + " (" + str(int(php_round((self.cfg.gasBuffer - 1) * 100))) + "% margin included) sent in one transaction: $" + Refunds.usd(returned - gas_micro_usd) + " net to you")
        else:
            body["why"] = "refund $" + Refunds.usd(returned) + " sent; ZEAM paid the gas"
        if state is not None:
            body["channelState"] = state
        return Refunds._answer(200, body)

    def _hand_over(self, ch, live, call, left, nonce):
        ident = ch["channelId"].lower()
        now = self.cfg.now_ms()
        transaction = {"chainId": self.cfg.chainId, "to": Address.checksum(call["to"]), "data": call["data"], "value": "0"}

        def reserve(r):
            if r is None:
                return None
            before = r.get("balance") if r.get("balance") is not None else "0"
            rest = n(before) - n(left)
            r["balance"] = str(rest) if rest > 0 else "0"
            if n(ch.get("chargedCumulativeAmount", "0")) < n(r.get("chargedCumulativeAmount", "0")):
                r["chargedCumulativeAmount"] = ch["chargedCumulativeAmount"]
            r["handedOver"] = {
                "units": php_str(left),
                "chainBalance": php_str(live["balance"]),
                "nonce": php_str(nonce),
                "at": now,
                "transaction": transaction,
            }
            return r

        try:
            self.channels.update(ident, reserve)
        except Exception as e:
            return Refunds._failed(409, "refund_error", _cut(str(e), 160), {"retry_after_seconds": Refunds.RETRY_SECS})
        time_ms = self._forget(ident)
        body = {
            "op": "refund_signed",
            "microUSD": self._micro_usd(ch, left),
            "transaction": transaction,
            "why": "signed refund of the full balance; send it at your own gas",
        }
        if time_ms > 0:
            body["timeReturnedMs"] = time_ms
        return Refunds._answer(200, body)

    def _forget(self, ident):
        meter = getattr(self.cfg, "meter", None)
        return meter.forget(ident) if meter is not None else 0
