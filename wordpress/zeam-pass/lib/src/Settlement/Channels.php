<?php

namespace ZeamPass\Settlement;

use ZeamPass\Big;

final class Channels
{
    private $cfg;
    private $store;
    private $chain;

    public function __construct(Config $cfg, Store $store, Chain $chain)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->chain = $chain;
    }

    public static function n($value)
    {
        if (Big::is($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return Big::init(0);
        }
        if (is_int($value)) {
            return Big::init($value);
        }
        return Big::init((string) $value, 10);
    }

    public static function max($a, $b)
    {
        return Big::cmp(self::n($a), self::n($b)) >= 0 ? self::n($a) : self::n($b);
    }

    public function get($channelId)
    {
        return $this->store->get(strtolower($channelId));
    }

    public function update($channelId, callable $fn)
    {
        return $this->store->update(strtolower($channelId), $fn);
    }

    public function repaired($channelId)
    {
        $channel = $this->get($channelId);
        if ($channel === null) {
            return null;
        }
        $now = $this->cfg->nowMs();
        $last = isset($channel['repairedAt']) ? (int) $channel['repairedAt'] : 0;
        if (empty($channel['handedOver']) && $now - $last < $this->cfg->repairEveryMs) {
            return $channel;
        }
        try {
            $st = $this->chain->channel($channelId);
        } catch (\Throwable $e) {
            return $channel;
        }
        $nonce = null;
        if (!empty($channel['handedOver'])) {
            try {
                $nonce = $this->chain->refundNonce($channelId);
            } catch (\Throwable $e) {
                $nonce = null;
            }
        }
        $cfg = $this->cfg;
        return $this->update($channelId, function ($c) use ($st, $nonce, $now, $cfg) {
            if ($c === null) {
                return null;
            }
            $c['repairedAt'] = $now;
            if (!empty($c['handedOver'])) {
                if ($nonce !== null && (string) $nonce !== (string) $c['handedOver']['nonce']) {
                    unset($c['handedOver']);
                    $c['balance'] = $st['balance'];
                }
            } elseif (isset($c['balance']) && Big::cmp(Channels::n($st['balance']), Channels::n($c['balance'])) < 0) {
                $c['balance'] = $st['balance'];
            }
            $charged = Channels::n(isset($c['chargedCumulativeAmount']) ? $c['chargedCumulativeAmount'] : '0');
            if (Big::cmp(Channels::n($st['totalClaimed']), $charged) > 0) {
                $c['chargedCumulativeAmount'] = $st['totalClaimed'];
                $c['totalClaimed'] = $st['totalClaimed'];
            }
            return $c;
        });
    }

    public function leaving(array $channel)
    {
        $id = $channel['channelId'];
        $stamped = isset($channel['withdrawRequestedAt']) && (int) $channel['withdrawRequestedAt'] > 0;
        try {
            $w = $this->chain->pendingWithdrawal($id);
        } catch (\Throwable $e) {
            return $stamped;
        }
        $leaving = Big::sign(self::n($w['amount'])) > 0;
        if ($leaving !== $stamped) {
            $now = $this->cfg->nowMs();
            $this->update($id, function ($c) use ($leaving, $now) {
                if ($c === null) {
                    return null;
                }
                $c['withdrawRequestedAt'] = $leaving ? $now : 0;
                return $c;
            });
        }
        return $leaving;
    }

    public function release($channelId, $pendingId = null)
    {
        $had = false;
        try {
            $this->update($channelId, function ($c) use ($pendingId, &$had) {
                if ($c === null || !isset($c['pendingRequest'])) {
                    return $c;
                }
                if ($pendingId !== null && (!isset($c['pendingRequest']['pendingId']) || $c['pendingRequest']['pendingId'] !== $pendingId)) {
                    return $c;
                }
                $had = true;
                unset($c['pendingRequest']);
                return $c;
            });
        } catch (\Throwable $e) {
            return false;
        }
        return $had;
    }

    public static function pendingLive(?array $channel, $now = 0)
    {
        return $channel !== null && isset($channel['pendingRequest']['expiresAt']) && (int) $channel['pendingRequest']['expiresAt'] > $now;
    }

    public static function claimable(array $c)
    {
        if (empty($c['signature']) || empty($c['signedMaxClaimable'])) {
            return false;
        }
        try {
            $charged = self::n(isset($c['chargedCumulativeAmount']) ? $c['chargedCumulativeAmount'] : '0');
            $signed = self::n($c['signedMaxClaimable']);
            $claimed = self::n(isset($c['totalClaimed']) ? $c['totalClaimed'] : '0');
            $balance = self::n(isset($c['balance']) ? $c['balance'] : '0');
        } catch (\Throwable $e) {
            return false;
        }
        if (Big::sign($charged) <= 0 || Big::cmp($charged, $claimed) <= 0 || Big::cmp($charged, $signed) > 0 || Big::cmp($charged, $balance) > 0) {
            return false;
        }
        return Big::cmp(Big::sub($charged, $claimed), Big::sub($balance, $claimed)) <= 0;
    }

    public static function earned(Config $cfg, array $c)
    {
        if ($cfg->meter === null || empty($c['channelId']) || empty($c['channelConfig']['token'])) {
            return $c;
        }
        $micro = $cfg->meter->unburnedMicro($c['channelId']);
        $asset = $micro > 0 ? $cfg->assetOf($c['channelConfig']['token']) : null;
        if ($asset === null) {
            return $c;
        }
        $charged = self::n(isset($c['chargedCumulativeAmount']) ? $c['chargedCumulativeAmount'] : '0');
        $claimed = self::n(isset($c['totalClaimed']) ? $c['totalClaimed'] : '0');
        $net = Big::sub($charged, Big::init($cfg->unitsOfMicroUSD($asset, $micro), 10));
        if (Big::cmp($net, $claimed) < 0) {
            $net = $claimed;
        }
        if (Big::cmp($net, $charged) >= 0) {
            return $c;
        }
        $c['chargedCumulativeAmount'] = Big::strval($net);
        return $c;
    }

    public static function refundable(array $c, array $live)
    {
        $charged = self::n(isset($c['chargedCumulativeAmount']) ? $c['chargedCumulativeAmount'] : '0');
        $left = Big::sub(self::n($live['balance']), self::max($charged, $live['claimed']));
        return Big::sign($left) > 0 ? Big::strval($left) : '0';
    }

    public static function claimEntry(array $c)
    {
        return [
            'voucher' => ['channel' => $c['channelConfig'], 'maxClaimableAmount' => (string) $c['signedMaxClaimable']],
            'signature' => $c['signature'],
            'totalClaimed' => (string) $c['chargedCumulativeAmount'],
        ];
    }
}
