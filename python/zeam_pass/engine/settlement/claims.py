from ..batch_settlement import BatchSettlement
from ..core import Address
from ..php import php_empty, php_round, to_int
from .channels import Channels, n
from .chain import Chain
from .gas_quote import GasQuote
from .payout import Payout
from .relay import Relay


class Claims:
    def __init__(self, cfg, store, chain=None, relay=None):
        self.cfg = cfg
        self.store = store
        self.chain = chain or Chain(cfg.rpcUrl)
        self.relay = relay or Relay(cfg.relayUrl)
        self.gas = GasQuote(cfg, self.chain, self.relay)
        self.channels = Channels(cfg, store, self.chain)
        self.payout = Payout(cfg, self.chain)

    def worth_claiming(self, asset, records):
        now = self.cfg.now_ms()
        found = []
        for raw in records:
            c = Channels.earned(self.cfg, raw)
            token = c.get("channelConfig", {}).get("token") if isinstance(c.get("channelConfig"), dict) else None
            if token is None or not Address.equals(token, asset["address"]) or not Channels.claimable(c):
                continue
            try:
                live = self.chain.live(c["channelId"])
            except Exception:
                continue
            if live["withdrawing"] and php_empty(c.get("withdrawRequestedAt")):
                def stamp(r):
                    if r is not None:
                        r["withdrawRequestedAt"] = now
                    return r
                self.channels.update(c["channelId"], stamp)
            charged = n(c["chargedCumulativeAmount"])
            if charged > n(live["balance"]) or charged <= n(live["claimed"]):
                continue
            delta = charged - n(live["claimed"])
            if delta > n(live["payable"]):
                continue
            micro = to_int(self.cfg.micro_usd_of(asset, str(delta)))
            urgent = False
            if live["withdrawing"]:
                gas = self.gas.micro_usd(self.cfg.gas["claimAlone"])
                urgent = gas is not None and micro * self.cfg.feeShare >= gas
            idle = now - to_int(c.get("lastRequestTimestamp", 0)) >= 1000 * self.cfg.idleClaimSecs
            if not live["withdrawing"] and not idle:
                continue
            found.append({"c": c, "live": live, "urgent": urgent, "microUSD": micro, "units": str(delta)})
        urgent = [x for x in found if x["urgent"]]
        rest = [x for x in found if not x["urgent"]]
        if not rest:
            return urgent
        worth = sum(x["microUSD"] for x in rest)
        gas = self.gas.micro_usd(self.cfg.gas["claimBase"] + self.cfg.gas["claimEntry"] * len(rest) + self.cfg.gas["payout"])
        ok = gas is not None and worth * self.cfg.feeShare >= gas
        return urgent + rest if ok else urgent

    def _claim_call(self, picked):
        claims = [Channels.claim_entry(x["c"]) for x in picked]
        signature = BatchSettlement.sign_claim_batch(self.cfg.signing_key(), claims, self.cfg.chainId)
        return {
            "what": f"claim {len(claims)} voucher(s)",
            "to": BatchSettlement.ESCROW,
            "data": BatchSettlement.encode_claim_with_signature(claims, signature),
        }

    def run(self):
        records = self.store.list()
        return {asset["symbol"]: self._run_asset(asset, records) for asset in self.cfg.assets}

    def _run_asset(self, asset, records):
        selected = self.worth_claiming(asset, records)[:max(1, int(self.cfg.maxClaimsPerBatch))]
        riding = sum(int(x["units"]) for x in selected)
        try:
            plan = self.payout.calls(asset["address"], bool(selected))
        except Exception as e:
            plan = {"calls": [], "owed": "0", "held": "0", "error": str(e)}
        payout_calls = plan["calls"]
        usd = 0.0
        payout_ok = False
        if payout_calls:
            units = str(riding + int(plan["owed"]) + int(plan["held"]))
            usd = self.cfg.micro_usd_of(asset, units) / 1e6
            gas_units = self.cfg.gas["payout"] + (self.cfg.gas["claimBase"] + len(selected) * self.cfg.gas["claimEntry"] if selected else 0)
            for c in payout_calls:
                if c["what"] == "create split":
                    gas_units += self.cfg.gas["createSplit"]
            payout_ok = self.gas.at_margin(usd, gas_units)
        claiming = selected if payout_ok else [x for x in selected if x["live"]["withdrawing"]]
        calls = []
        if claiming:
            calls.append(self._claim_call(claiming))
        if payout_ok:
            calls.extend(payout_calls)
        out = {"selected": len(selected), "claimed": [], "valueUSD": php_round(usd, 6), "calls": []}
        if "error" in plan:
            out["error"] = plan["error"]
        if payout_calls and not payout_ok:
            out["waiting"] = "below break-even: waits until the fee share covers the gas"
        if not calls:
            return out
        results = self.relay.send(calls, self.cfg.relay_split())
        for i, call in enumerate(calls):
            out["calls"].append({"what": call["what"], **results[i]})
        if claiming and "hash" in results[0]:
            for x in claiming:
                ident = x["c"]["channelId"]
                try:
                    st = self.chain.channel(ident)
                except Exception:
                    continue

                def sync(r, st=st):
                    if r is None:
                        return None
                    if n(st["totalClaimed"]) > n(r.get("totalClaimed", "0")):
                        r["totalClaimed"] = st["totalClaimed"]
                    if n(st["balance"]) < n(r.get("balance", "0")):
                        r["balance"] = st["balance"]
                    return r

                self.channels.update(ident, sync)
                out["claimed"].append(ident)
        return out
