<?php

namespace ZeamPass;

final class Splits
{
    const PULL_SPLIT_FACTORY = '0x6B9118074aB15142d7524E8c4ea8f62A3Bdb98f1';
    const WAREHOUSE = '0x8fb66F38cF86A3d5e8768f8F1754A24A6c661Fb8';
    const SPLIT_WALLET_IMPLEMENTATION = '0x98254AeDb6B2c30b70483064367f0BA24ca86244';
    const TOTAL = 1000000;
    const FEE_PPM = 99900;
    const REALM = 'ZEAM Pass';

    const SPLIT = '(address[],uint256[],uint256,uint16)';

    public static function feeSplit($payout, $fee, $feePpm = self::FEE_PPM)
    {
        $payout = Address::checksum($payout);
        $fee = Address::checksum($fee);
        if ($payout === $fee) {
            throw new \InvalidArgumentException('the payout and the fee recipient must differ');
        }
        return [
            'recipients' => [$payout, $fee],
            'allocations' => [(string) (self::TOTAL - (int) $feePpm), (string) (int) $feePpm],
            'totalAllocation' => (string) self::TOTAL,
            'distributionIncentive' => 0,
        ];
    }

    public static function wholeSplit($fee)
    {
        return [
            'recipients' => [Address::checksum($fee)],
            'allocations' => [(string) self::TOTAL],
            'totalAllocation' => (string) self::TOTAL,
            'distributionIncentive' => 0,
        ];
    }

    public static function saltFor($realm, $name)
    {
        return Keccak::utf8($realm . ' seller ' . $name);
    }

    public static function splitTuple(array $split)
    {
        return [
            array_map([Address::class, 'checksum'], array_values($split['recipients'])),
            array_map([Num::class, 'dec'], array_values($split['allocations'])),
            Num::dec($split['totalAllocation']),
            (int) Num::dec($split['distributionIncentive']),
        ];
    }

    public static function create2Salt(array $split, $owner, $salt)
    {
        $encoded = Abi::encode([self::SPLIT, 'address'], [self::splitTuple($split), Address::checksum($owner)]);
        return Keccak::hex(Hex::toBin($encoded) . Hex::toBin($salt));
    }

    public static function cloneInitCode($implementation = self::SPLIT_WALLET_IMPLEMENTATION)
    {
        return '0x60593d8160093d39f336602c57343d527f'
            . '9e4ac34f21c619cefc926c8bd93b54bf5a39c7ab2127a895af1cc0691d7e3dff'
            . '593da1005b3d3d3d3d363d3d37363d73'
            . strtolower(substr(Address::checksum($implementation), 2))
            . '5af43d3d93803e605757fd5bf3'
            . str_repeat('00', 15);
    }

    public static function create2Address($deployer, $salt, $initCode)
    {
        $hash = Keccak::hash("\xff" . Hex::toBin(Address::checksum($deployer)) . Hex::toBin($salt) . Keccak::hash(Hex::toBin($initCode)));
        return Address::checksum(Hex::fromBin(substr($hash, 12)));
    }

    public static function predictAddress(array $split, $owner, $salt)
    {
        return self::create2Address(self::PULL_SPLIT_FACTORY, self::create2Salt($split, $owner, $salt), self::cloneInitCode());
    }

    public static function encodeCreateSplitDeterministic(array $split, $owner, $creator, $salt)
    {
        return Abi::encodeCall('createSplitDeterministic(' . self::SPLIT . ',address,address,bytes32)', [self::splitTuple($split), Address::checksum($owner), Address::checksum($creator), $salt]);
    }

    public static function encodeIsDeployed(array $split, $owner, $salt)
    {
        return Abi::encodeCall('isDeployed(' . self::SPLIT . ',address,bytes32)', [self::splitTuple($split), Address::checksum($owner), $salt]);
    }

    public static function decodeIsDeployed($data)
    {
        list($address, $deployed) = Abi::decode(['address', 'bool'], $data);
        return ['address' => $address, 'deployed' => $deployed];
    }

    public static function encodePredictDeterministicAddress(array $split, $owner, $salt)
    {
        return Abi::encodeCall('predictDeterministicAddress(' . self::SPLIT . ',address,bytes32)', [self::splitTuple($split), Address::checksum($owner), $salt]);
    }

    public static function decodeAddress($data)
    {
        return Abi::decode(['address'], $data)[0];
    }

    public static function encodeDistribute(array $split, $token, $distributor)
    {
        return Abi::encodeCall('distribute(' . self::SPLIT . ',address,address)', [self::splitTuple($split), Address::checksum($token), Address::checksum($distributor)]);
    }

    public static function encodeGetSplitBalance($token)
    {
        return Abi::encodeCall('getSplitBalance(address)', [Address::checksum($token)]);
    }

    public static function decodeGetSplitBalance($data)
    {
        list($splitBalance, $warehouseBalance) = Abi::decode(['uint256', 'uint256'], $data);
        return ['splitBalance' => $splitBalance, 'warehouseBalance' => $warehouseBalance];
    }

    public static function encodeWarehouseWithdraw($owner, $token)
    {
        return Abi::encodeCall('withdraw(address,address)', [Address::checksum($owner), Address::checksum($token)]);
    }

    public static function tokenId($token)
    {
        return Big::strval(Big::init(substr(Address::checksum($token), 2), 16), 10);
    }

    public static function encodeWarehouseBalanceOf($owner, $tokenId)
    {
        $id = Address::isAddress($tokenId) ? self::tokenId($tokenId) : $tokenId;
        return Abi::encodeCall('balanceOf(address,uint256)', [Address::checksum($owner), $id]);
    }
}
