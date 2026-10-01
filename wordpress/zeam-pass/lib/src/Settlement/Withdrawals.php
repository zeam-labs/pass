<?php

namespace ZeamPass\Settlement;

use ZeamPass\Big;

final class Withdrawals
{
    private $cfg;
    private $store;
    private $chain;

    public function __construct(Config $cfg, Store $store, ?Chain $chain = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->chain = $chain ?: new Chain($cfg->rpcUrl);
    }

    public function run()
    {
        $out = ['checked' => 0, 'withdrawing' => [], 'cleared' => [], 'errors' => 0];
        $now = $this->cfg->nowMs();
        foreach ($this->store->list() as $c) {
            if (!isset($c['channelId']) || Big::sign(Channels::n(isset($c['balance']) ? $c['balance'] : '0')) <= 0) {
                continue;
            }
            $id = strtolower($c['channelId']);
            try {
                $w = $this->chain->pendingWithdrawal($id);
            } catch (\Throwable $e) {
                $out['errors']++;
                continue;
            }
            $out['checked']++;
            $leaving = Big::sign(Channels::n($w['amount'])) > 0;
            $stamped = !empty($c['withdrawRequestedAt']);
            if ($leaving) {
                $out['withdrawing'][] = $id;
            }
            if ($leaving === $stamped) {
                continue;
            }
            $this->store->update($id, function ($r) use ($leaving, $now) {
                if ($r === null) {
                    return null;
                }
                $r['withdrawRequestedAt'] = $leaving ? $now : 0;
                return $r;
            });
            if (!$leaving) {
                $out['cleared'][] = $id;
            }
        }
        return $out;
    }
}
