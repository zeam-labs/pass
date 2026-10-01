<?php

namespace ZeamPass\Settlement;

use ZeamPass\Abi;
use ZeamPass\Address;
use ZeamPass\BatchSettlement;
use ZeamPass\Big;
use ZeamPass\Erc20;
use ZeamPass\Hex;
use ZeamPass\Num;
use ZeamPass\Rpc;
use ZeamPass\Splits;

final class Chain
{
    const PERMIT2 = '0x000000000022D473030F116dDEE9F6B43aC78BA3';
    const ERC1271_MAGIC = '0x1626ba7e';

    private $rpc;

    public function __construct($rpc)
    {
        if (is_string($rpc)) {
            $rpc = new Rpc($rpc, 10, 5);
        }
        if (!is_object($rpc) || !method_exists($rpc, 'call')) {
            throw new \InvalidArgumentException('a chain reader needs an RPC URL or an object with call(method, params)');
        }
        $this->rpc = $rpc;
    }

    public function rpc($method, array $params = [])
    {
        return $this->rpc->call($method, $params);
    }

    public function call($to, $data, $from = null)
    {
        $tx = ['to' => Address::checksum($to), 'data' => Hex::lower($data)];
        if ($from !== null) {
            $tx['from'] = Address::checksum($from);
        }
        return (string) $this->rpc('eth_call', [$tx, 'latest']);
    }

    public function gasPrice()
    {
        return Num::dec($this->rpc('eth_gasPrice'));
    }

    public function code($address)
    {
        $code = $this->rpc('eth_getCode', [Address::checksum($address), 'latest']);
        return is_string($code) ? strtolower($code) : '0x';
    }

    public function hasCode($address)
    {
        $code = $this->code($address);
        return $code !== '0x' && $code !== '';
    }

    public function channel($channelId)
    {
        $c = BatchSettlement::decodeChannels($this->call(BatchSettlement::ESCROW, BatchSettlement::encodeChannels($channelId)));
        return ['balance' => Num::dec($c['balance']), 'totalClaimed' => Num::dec($c['totalClaimed'])];
    }

    public function pendingWithdrawal($channelId)
    {
        $w = BatchSettlement::decodePendingWithdrawals($this->call(BatchSettlement::ESCROW, BatchSettlement::encodePendingWithdrawals($channelId)));
        return ['amount' => Num::dec($w['amount']), 'initiatedAt' => (int) Num::dec($w['initiatedAt'])];
    }

    public function refundNonce($channelId)
    {
        return Num::dec(BatchSettlement::decodeRefundNonce($this->call(BatchSettlement::ESCROW, BatchSettlement::encodeRefundNonce($channelId))));
    }

    public function state($channelId)
    {
        $c = $this->channel($channelId);
        $w = $this->pendingWithdrawal($channelId);
        return [
            'balance' => $c['balance'],
            'totalClaimed' => $c['totalClaimed'],
            'withdrawRequestedAt' => $w['initiatedAt'],
            'withdrawing' => Big::sign(Big::init($w['amount'], 10)) > 0,
            'refundNonce' => $this->refundNonce($channelId),
        ];
    }

    public function live($channelId)
    {
        $c = $this->channel($channelId);
        $w = $this->pendingWithdrawal($channelId);
        $payable = Big::sub(Big::init($c['balance'], 10), Big::init($c['totalClaimed'], 10));
        return [
            'balance' => $c['balance'],
            'claimed' => $c['totalClaimed'],
            'payable' => Big::sign($payable) < 0 ? '0' : Big::strval($payable),
            'withdrawing' => Big::sign(Big::init($w['amount'], 10)) > 0,
        ];
    }

    public function balanceOf($token, $owner)
    {
        return Num::dec(Erc20::decodeUint256($this->call($token, Erc20::encodeBalanceOf($owner))));
    }

    public function allowance($token, $owner, $spender)
    {
        $data = Abi::encodeCall('allowance(address,address)', [Address::checksum($owner), Address::checksum($spender)]);
        return Num::dec(Erc20::decodeUint256($this->call($token, $data)));
    }

    public function receivers($receiver, $token)
    {
        $r = BatchSettlement::decodeReceivers($this->call(BatchSettlement::ESCROW, BatchSettlement::encodeReceivers($receiver, $token)));
        return ['totalClaimed' => Num::dec($r['totalClaimed']), 'totalSettled' => Num::dec($r['totalSettled'])];
    }

    public function splitBalance($split, $token)
    {
        $b = Splits::decodeGetSplitBalance($this->call($split, Splits::encodeGetSplitBalance($token)));
        return Big::strval(Big::add(Big::init(Num::dec($b['splitBalance']), 10), Big::init(Num::dec($b['warehouseBalance']), 10)));
    }

    public function isValidSignature($address, $digest, $signature)
    {
        try {
            $out = $this->call($address, Abi::encodeCall('isValidSignature(bytes32,bytes)', [$digest, Hex::lower($signature)]));
        } catch (\Throwable $e) {
            return false;
        }
        return strncasecmp($out, self::ERC1271_MAGIC, 10) === 0;
    }

    public function receipt($hash)
    {
        $r = $this->rpc('eth_getTransactionReceipt', [strtolower($hash)]);
        return is_array($r) ? $r : null;
    }
}
