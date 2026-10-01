<?php

namespace ZeamPass;

final class Erc20
{
    const USDC_BASE = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
    const USDC_BASE_NAME = 'USD Coin';
    const USDC_BASE_VERSION = '2';

    public static function encodeBalanceOf($owner)
    {
        return Abi::encodeCall('balanceOf(address)', [Address::checksum($owner)]);
    }

    public static function decodeUint256($data)
    {
        return Abi::decode(['uint256'], $data)[0];
    }
}
