import re

from ..php import is_int, php_empty, php_str, to_int

_DEC = re.compile(r"^\s*-?[0-9]+\s*$")


def n(value):
    if value is None or value == "":
        return 0
    if is_int(value):
        return value
    text = php_str(value)
    if not _DEC.match(text):
        raise ValueError(f"not a decimal integer: {text[:80]}")
    return int(text)


def bigger(a, b):
    return n(a) if n(a) >= n(b) else n(b)


class Channels:
    n = staticmethod(n)
    max = staticmethod(bigger)

    def __init__(self, cfg, store, chain):
        self.cfg = cfg
        self.store = store
        self.chain = chain

    def get(self, channel_id):
        return self.store.get(channel_id.lower())

    def update(self, channel_id, fn):
        return self.store.update(channel_id.lower(), fn)

    def repaired(self, channel_id):
        channel = self.get(channel_id)
        if channel is None:
            return None
        now = self.cfg.now_ms()
        last = to_int(channel.get("repairedAt", 0))
        if php_empty(channel.get("handedOver")) and now - last < self.cfg.repairEveryMs:
            return channel
        try:
            st = self.chain.channel(channel_id)
        except Exception:
            return channel
        nonce = None
        if not php_empty(channel.get("handedOver")):
            try:
                nonce = self.chain.refund_nonce(channel_id)
            except Exception:
                nonce = None

        def fix(c):
            if c is None:
                return None
            c["repairedAt"] = now
            if not php_empty(c.get("handedOver")):
                if nonce is not None and php_str(nonce) != php_str(c["handedOver"].get("nonce")):
                    del c["handedOver"]
                    c["balance"] = st["balance"]
            elif c.get("balance") is not None and n(st["balance"]) < n(c["balance"]):
                c["balance"] = st["balance"]
            charged = n(c.get("chargedCumulativeAmount", "0"))
            if n(st["totalClaimed"]) > charged:
                c["chargedCumulativeAmount"] = st["totalClaimed"]
                c["totalClaimed"] = st["totalClaimed"]
            return c

        return self.update(channel_id, fix)

    def leaving(self, channel):
        ident = channel["channelId"]
        stamped = to_int(channel.get("withdrawRequestedAt")) > 0
        try:
            w = self.chain.pending_withdrawal(ident)
        except Exception:
            return stamped
        leaving = n(w["amount"]) > 0
        if leaving != stamped:
            now = self.cfg.now_ms()

            def stamp(c):
                if c is None:
                    return None
                c["withdrawRequestedAt"] = now if leaving else 0
                return c

            self.update(ident, stamp)
        return leaving

    def release(self, channel_id, pending_id=None):
        had = [False]

        def drop(c):
            if c is None or "pendingRequest" not in c:
                return c
            pending = c["pendingRequest"] if isinstance(c["pendingRequest"], dict) else {}
            if pending_id is not None and pending.get("pendingId") != pending_id:
                return c
            had[0] = True
            del c["pendingRequest"]
            return c

        try:
            self.update(channel_id, drop)
        except Exception:
            return False
        return had[0]

    @staticmethod
    def pending_live(channel, now=0):
        if channel is None:
            return False
        pending = channel.get("pendingRequest")
        return isinstance(pending, dict) and pending.get("expiresAt") is not None and to_int(pending["expiresAt"]) > now

    @staticmethod
    def claimable(c):
        if php_empty(c.get("signature")) or php_empty(c.get("signedMaxClaimable")):
            return False
        try:
            charged = n(c.get("chargedCumulativeAmount", "0"))
            signed = n(c["signedMaxClaimable"])
            claimed = n(c.get("totalClaimed", "0"))
            balance = n(c.get("balance", "0"))
        except Exception:
            return False
        if charged <= 0 or charged <= claimed or charged > signed or charged > balance:
            return False
        return charged - claimed <= balance - claimed

    @staticmethod
    def earned(cfg, c):
        meter = getattr(cfg, "meter", None)
        config = c.get("channelConfig") if isinstance(c, dict) else None
        if meter is None or not c.get("channelId") or not isinstance(config, dict) or not config.get("token"):
            return c
        micro = meter.unburned_micro(c["channelId"])
        asset = cfg.asset_of(config["token"]) if micro > 0 else None
        if asset is None:
            return c
        try:
            charged = n(c.get("chargedCumulativeAmount", "0"))
            claimed = n(c.get("totalClaimed", "0"))
        except ValueError:
            return c
        net = max(charged - int(cfg.units_of_micro_usd(asset, micro)), claimed)
        return c if net >= charged else {**c, "chargedCumulativeAmount": str(net)}

    @staticmethod
    def refundable(c, live):
        charged = n(c.get("chargedCumulativeAmount", "0"))
        left = n(live["balance"]) - bigger(charged, live["claimed"])
        return str(left) if left > 0 else "0"

    @staticmethod
    def claim_entry(c):
        return {
            "voucher": {"channel": c["channelConfig"], "maxClaimableAmount": php_str(c["signedMaxClaimable"])},
            "signature": c["signature"],
            "totalClaimed": php_str(c["chargedCumulativeAmount"]),
        }
