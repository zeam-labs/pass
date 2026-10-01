<?php

namespace ZeamPass\Settlement;

use ZeamPass\Address;
use ZeamPass\BatchSettlement;
use ZeamPass\Big;
use ZeamPass\Splits;

final class Payout
{
    const DUST = 100;

    private $cfg;
    private $chain;

    public function __construct(Config $cfg, Chain $chain)
    {
        $this->cfg = $cfg;
        $this->chain = $chain;
    }

    public function calls($token, $claimsRiding = false)
    {
        $receiver = $this->cfg->receiver;
        $min = Big::init((string) $this->cfg->payoutMinUnits, 10);
        $calls = [];
        $incoming = Big::init(0);
        $r = $this->chain->receivers($receiver, $token);
        $owed = Big::sub(Big::init($r['totalClaimed'], 10), Big::init($r['totalSettled'], 10));
        if ($claimsRiding || Big::cmp($owed, $min) >= 0) {
            $incoming = $claimsRiding && Big::cmp($owed, $min) < 0 ? Big::add($min, 1) : $owed;
            $calls[] = [
                'what' => $claimsRiding ? 'settle what the claims riding with it bring' : 'settle ' . Big::strval($incoming),
                'to' => BatchSettlement::ESCROW,
                'data' => BatchSettlement::encodeSettle($receiver, $token),
            ];
        }
        $deployed = $this->chain->hasCode($receiver);
        $held = Big::init($deployed ? $this->chain->splitBalance($receiver, $token) : $this->chain->balanceOf($token, $receiver), 10);
        $heldErc20 = $deployed ? Big::init($this->chain->balanceOf($token, $receiver), 10) : $held;
        $result = ['calls' => $calls, 'owed' => Big::strval(Big::sign($owed) > 0 ? $owed : Big::init(0)), 'held' => Big::strval($heldErc20), 'deployed' => $deployed];
        if (Big::cmp(Big::add($held, $incoming), $min) <= 0) {
            return $result;
        }
        if (Big::sign($incoming) === 0 && Big::cmp($held, Big::init(self::DUST)) < 0) {
            return $result;
        }
        if (!$deployed) {
            $calls[] = [
                'what' => 'create split',
                'to' => Splits::PULL_SPLIT_FACTORY,
                'data' => Splits::encodeCreateSplitDeterministic($this->cfg->split, Address::ZERO, $this->cfg->payout, $this->cfg->salt),
            ];
        }
        $calls[] = [
            'what' => $claimsRiding ? 'distribute' : 'distribute ' . Big::strval(Big::add($held, $incoming)),
            'to' => $receiver,
            'data' => Splits::encodeDistribute($this->cfg->split, $token, Address::ZERO),
        ];
        $calls[] = [
            'what' => 'pay the seller',
            'to' => Splits::WAREHOUSE,
            'data' => Splits::encodeWarehouseWithdraw($this->cfg->split['recipients'][0], $token),
        ];
        $result['calls'] = $calls;
        return $result;
    }
}
