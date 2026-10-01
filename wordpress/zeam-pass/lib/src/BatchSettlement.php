<?php

namespace ZeamPass;

final class BatchSettlement
{
    const ESCROW = '0x4020074e9dF2ce1deE5A9C1b5c3f541D02a10003';
    const ERC3009_DEPOSIT_COLLECTOR = '0x4020806089470a89826cB9fB1f4059150b550004';
    const MULTICALL3 = '0xcA11bde05977b3631167028862bE2a173976CA11';
    const PERMIT2_DEPOSIT_COLLECTOR = '0x4020425FAf3B746C082C2f942b4E5159887B0005';
    const DOMAIN_NAME = 'x402 Batch Settlement';
    const DOMAIN_VERSION = '1';
    const MIN_WITHDRAW_DELAY = 900;
    const MAX_WITHDRAW_DELAY = 2592000;
    const ERC6492_MAGIC = '6492649264926492649264926492649264926492649264926492649264926492';

    const CONFIG = '(address,address,address,address,address,uint40,bytes32)';
    const VOUCHER_CLAIMS = '(((address,address,address,address,address,uint40,bytes32),uint128),bytes,uint128)[]';

    const CHANNEL_CONFIG_TYPES = [
        'ChannelConfig' => [
            ['name' => 'payer', 'type' => 'address'],
            ['name' => 'payerAuthorizer', 'type' => 'address'],
            ['name' => 'receiver', 'type' => 'address'],
            ['name' => 'receiverAuthorizer', 'type' => 'address'],
            ['name' => 'token', 'type' => 'address'],
            ['name' => 'withdrawDelay', 'type' => 'uint40'],
            ['name' => 'salt', 'type' => 'bytes32'],
        ],
    ];

    const VOUCHER_TYPES = [
        'Voucher' => [
            ['name' => 'channelId', 'type' => 'bytes32'],
            ['name' => 'maxClaimableAmount', 'type' => 'uint128'],
        ],
    ];

    const REFUND_TYPES = [
        'Refund' => [
            ['name' => 'channelId', 'type' => 'bytes32'],
            ['name' => 'nonce', 'type' => 'uint256'],
            ['name' => 'amount', 'type' => 'uint128'],
        ],
    ];

    const CLAIM_BATCH_TYPES = [
        'ClaimBatch' => [
            ['name' => 'claims', 'type' => 'ClaimEntry[]'],
        ],
        'ClaimEntry' => [
            ['name' => 'channelId', 'type' => 'bytes32'],
            ['name' => 'maxClaimableAmount', 'type' => 'uint128'],
            ['name' => 'totalClaimed', 'type' => 'uint128'],
        ],
    ];

    const RECEIVE_AUTHORIZATION_TYPES = [
        'ReceiveWithAuthorization' => [
            ['name' => 'from', 'type' => 'address'],
            ['name' => 'to', 'type' => 'address'],
            ['name' => 'value', 'type' => 'uint256'],
            ['name' => 'validAfter', 'type' => 'uint256'],
            ['name' => 'validBefore', 'type' => 'uint256'],
            ['name' => 'nonce', 'type' => 'bytes32'],
        ],
    ];

    const TRANSFER_AUTHORIZATION_TYPES = [
        'TransferWithAuthorization' => [
            ['name' => 'from', 'type' => 'address'],
            ['name' => 'to', 'type' => 'address'],
            ['name' => 'value', 'type' => 'uint256'],
            ['name' => 'validAfter', 'type' => 'uint256'],
            ['name' => 'validBefore', 'type' => 'uint256'],
            ['name' => 'nonce', 'type' => 'bytes32'],
        ],
    ];

    public static function domain($chainId)
    {
        return [
            'name' => self::DOMAIN_NAME,
            'version' => self::DOMAIN_VERSION,
            'chainId' => (int) $chainId,
            'verifyingContract' => Address::checksum(self::ESCROW),
        ];
    }

    public static function config(array $config)
    {
        foreach (['payer', 'payerAuthorizer', 'receiver', 'receiverAuthorizer', 'token', 'withdrawDelay', 'salt'] as $k) {
            if (!array_key_exists($k, $config)) {
                throw new \InvalidArgumentException(esc_html("channel config is missing $k"));
            }
        }
        return [
            'payer' => Address::checksum($config['payer']),
            'payerAuthorizer' => Address::checksum($config['payerAuthorizer']),
            'receiver' => Address::checksum($config['receiver']),
            'receiverAuthorizer' => Address::checksum($config['receiverAuthorizer']),
            'token' => Address::checksum($config['token']),
            'withdrawDelay' => (int) Num::dec($config['withdrawDelay']),
            'salt' => Hex::lower($config['salt']),
        ];
    }

    public static function configTuple(array $config)
    {
        $c = self::config($config);
        return [$c['payer'], $c['payerAuthorizer'], $c['receiver'], $c['receiverAuthorizer'], $c['token'], $c['withdrawDelay'], $c['salt']];
    }

    public static function channelId(array $config, $chainId)
    {
        return TypedData::hash(self::domain($chainId), self::CHANNEL_CONFIG_TYPES, 'ChannelConfig', self::config($config));
    }

    public static function channelIdMatches(array $config, $channelId, $chainId)
    {
        return Hex::isHex($channelId, 32) && strtolower(self::channelId($config, $chainId)) === strtolower($channelId);
    }

    public static function validateChannelConfig(array $config, $channelId, $chainId, $payTo, $receiverAuthorizer, $asset, $withdrawDelay = null)
    {
        $c = self::config($config);
        if (!self::channelIdMatches($c, $channelId, $chainId)) {
            return 'invalid_batch_settlement_evm_channel_id_mismatch';
        }
        if (!Address::equals($c['receiver'], $payTo)) {
            return 'invalid_batch_settlement_evm_receiver_mismatch';
        }
        if (!$receiverAuthorizer || Address::equals($receiverAuthorizer, Address::ZERO) || !Address::equals($c['receiverAuthorizer'], $receiverAuthorizer)) {
            return 'invalid_batch_settlement_evm_receiver_authorizer_mismatch';
        }
        if (!Address::equals($c['token'], $asset)) {
            return 'invalid_batch_settlement_evm_token_mismatch';
        }
        if ($withdrawDelay !== null && $c['withdrawDelay'] !== (int) $withdrawDelay) {
            return 'invalid_batch_settlement_evm_withdraw_delay_mismatch';
        }
        if ($c['withdrawDelay'] < self::MIN_WITHDRAW_DELAY || $c['withdrawDelay'] > self::MAX_WITHDRAW_DELAY) {
            return 'invalid_batch_settlement_evm_withdraw_delay_out_of_range';
        }
        return null;
    }

    public static function voucherDigest($channelId, $maxClaimableAmount, $chainId)
    {
        return TypedData::hash(self::domain($chainId), self::VOUCHER_TYPES, 'Voucher', [
            'channelId' => $channelId,
            'maxClaimableAmount' => $maxClaimableAmount,
        ]);
    }

    public static function signVoucher($key, $channelId, $maxClaimableAmount, $chainId)
    {
        return Secp256k1::signHash($key, self::voucherDigest($channelId, $maxClaimableAmount, $chainId));
    }

    public static function recoverVoucher($channelId, $maxClaimableAmount, $signature, $chainId)
    {
        return Secp256k1::recoverHash(self::voucherDigest($channelId, $maxClaimableAmount, $chainId), $signature);
    }

    public static function verifyVoucher(array $config, $channelId, $maxClaimableAmount, $signature, $chainId)
    {
        $c = self::config($config);
        $expected = Address::equals($c['payerAuthorizer'], Address::ZERO) ? $c['payer'] : $c['payerAuthorizer'];
        try {
            return Address::equals(self::recoverVoucher($channelId, $maxClaimableAmount, $signature, $chainId), $expected);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public static function claimEntries(array $claims, $chainId)
    {
        $entries = [];
        foreach ($claims as $c) {
            $entries[] = [
                'channelId' => self::channelId($c['voucher']['channel'], $chainId),
                'maxClaimableAmount' => Num::dec($c['voucher']['maxClaimableAmount']),
                'totalClaimed' => Num::dec($c['totalClaimed']),
            ];
        }
        return $entries;
    }

    public static function claimBatchDigest(array $claims, $chainId)
    {
        return TypedData::hash(self::domain($chainId), self::CLAIM_BATCH_TYPES, 'ClaimBatch', [
            'claims' => self::claimEntries($claims, $chainId),
        ]);
    }

    public static function signClaimBatch($key, array $claims, $chainId)
    {
        return Secp256k1::signHash($key, self::claimBatchDigest($claims, $chainId));
    }

    public static function refundDigest($channelId, $nonce, $amount, $chainId)
    {
        return TypedData::hash(self::domain($chainId), self::REFUND_TYPES, 'Refund', [
            'channelId' => $channelId,
            'nonce' => $nonce,
            'amount' => $amount,
        ]);
    }

    public static function signRefund($key, $channelId, $amount, $nonce, $chainId)
    {
        return Secp256k1::signHash($key, self::refundDigest($channelId, $nonce, $amount, $chainId));
    }

    public static function erc3009DepositNonce($channelId, $salt)
    {
        return Keccak::hashHex(Abi::encode(['bytes32', 'uint256'], [$channelId, $salt]));
    }

    public static function erc3009CollectorData($validAfter, $validBefore, $salt, $signature)
    {
        return Abi::encode(['uint256', 'uint256', 'uint256', 'bytes'], [$validAfter, $validBefore, $salt, $signature]);
    }

    public static function permit2CollectorData($nonce, $deadline, $permit2Signature, $eip2612PermitData = '0x')
    {
        return Abi::encode(['uint256', 'uint256', 'bytes', 'bytes'], [$nonce, $deadline, $permit2Signature, $eip2612PermitData]);
    }

    public static function tokenDomain($asset, $name, $version, $chainId)
    {
        return [
            'name' => (string) $name,
            'version' => (string) $version,
            'chainId' => (int) $chainId,
            'verifyingContract' => Address::checksum($asset),
        ];
    }

    public static function receiveAuthorization($payer, $amount, $validAfter, $validBefore, $nonce)
    {
        return [
            'from' => Address::checksum($payer),
            'to' => Address::checksum(self::ERC3009_DEPOSIT_COLLECTOR),
            'value' => Num::dec($amount),
            'validAfter' => Num::dec($validAfter),
            'validBefore' => Num::dec($validBefore),
            'nonce' => $nonce,
        ];
    }

    public static function receiveAuthorizationDigest(array $tokenDomain, array $authorization)
    {
        return TypedData::hash($tokenDomain, self::RECEIVE_AUTHORIZATION_TYPES, 'ReceiveWithAuthorization', $authorization);
    }

    public static function signReceiveAuthorization($key, array $tokenDomain, array $authorization)
    {
        return Secp256k1::signHash($key, self::receiveAuthorizationDigest($tokenDomain, $authorization));
    }

    public static function transferAuthorizationTypedData(array $tokenDomain, array $authorization)
    {
        $d = $tokenDomain;
        $a = $authorization;
        return [
            'domain' => ['name' => (string) $d['name'], 'version' => (string) $d['version'], 'chainId' => (int) $d['chainId'], 'verifyingContract' => Address::checksum($d['verifyingContract'])],
            'types' => ['TransferWithAuthorization' => array_map(function ($f) {
                return ['name' => $f['name'], 'type' => $f['type']];
            }, self::TRANSFER_AUTHORIZATION_TYPES['TransferWithAuthorization'])],
            'primaryType' => 'TransferWithAuthorization',
            'message' => ['from' => Address::checksum($a['from']), 'to' => Address::checksum($a['to']), 'value' => Num::dec($a['value']), 'validAfter' => Num::dec($a['validAfter']), 'validBefore' => Num::dec($a['validBefore']), 'nonce' => Hex::lower($a['nonce'])],
        ];
    }

    public static function transferAuthorizationDigest(array $tokenDomain, array $authorization)
    {
        return TypedData::hash($tokenDomain, self::TRANSFER_AUTHORIZATION_TYPES, 'TransferWithAuthorization', $authorization);
    }

    public static function signTransferAuthorization($key, array $tokenDomain, array $authorization)
    {
        return Secp256k1::signHash($key, self::transferAuthorizationDigest($tokenDomain, $authorization));
    }

    public static function parseErc6492Signature($signature)
    {
        $hex = Hex::lower($signature);
        if (strlen($hex) < 66 || substr($hex, -64) !== self::ERC6492_MAGIC) {
            return ['address' => null, 'data' => null, 'signature' => $hex];
        }
        list($address, $data, $inner) = Abi::decode(['address', 'bytes', 'bytes'], '0x' . substr($hex, 2, -64));
        return ['address' => $address, 'data' => $data, 'signature' => $inner];
    }

    public static function verifyErc3009Deposit(array $config, $amount, array $authorization, $asset, $name, $version, $chainId)
    {
        $c = self::config($config);
        $parsed = self::parseErc6492Signature($authorization['signature']);
        $nonce = self::erc3009DepositNonce(self::channelId($c, $chainId), $authorization['salt']);
        $digest = self::receiveAuthorizationDigest(
            self::tokenDomain($asset, $name, $version, $chainId),
            self::receiveAuthorization($c['payer'], $amount, $authorization['validAfter'], $authorization['validBefore'], $nonce)
        );
        try {
            return Address::equals(Secp256k1::recoverHash($digest, $parsed['signature']), $c['payer']);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public static function erc3009DepositCollectorData(array $authorization)
    {
        $parsed = self::parseErc6492Signature($authorization['signature']);
        return self::erc3009CollectorData($authorization['validAfter'], $authorization['validBefore'], $authorization['salt'], $parsed['signature']);
    }

    public static function voucherClaimTuples(array $claims)
    {
        $out = [];
        foreach ($claims as $c) {
            $out[] = [
                [self::configTuple($c['voucher']['channel']), Num::dec($c['voucher']['maxClaimableAmount'])],
                Hex::lower($c['signature']),
                Num::dec($c['totalClaimed']),
            ];
        }
        return $out;
    }

    public static function encodeDeposit(array $config, $amount, $collector, $collectorData)
    {
        return Abi::encodeCall('deposit(' . self::CONFIG . ',uint128,address,bytes)', [self::configTuple($config), $amount, Address::checksum($collector), $collectorData]);
    }

    public static function encodeErc3009Deposit(array $config, $amount, array $authorization)
    {
        return self::encodeDeposit($config, $amount, self::ERC3009_DEPOSIT_COLLECTOR, self::erc3009DepositCollectorData($authorization));
    }

    public static function encodeClaim(array $claims)
    {
        return Abi::encodeCall('claim(' . self::VOUCHER_CLAIMS . ')', [self::voucherClaimTuples($claims)]);
    }

    public static function encodeClaimWithSignature(array $claims, $authorizerSignature)
    {
        return Abi::encodeCall('claimWithSignature(' . self::VOUCHER_CLAIMS . ',bytes)', [self::voucherClaimTuples($claims), $authorizerSignature]);
    }

    public static function encodeRefundWithSignature(array $config, $amount, $nonce, $receiverAuthorizerSignature)
    {
        return Abi::encodeCall('refundWithSignature(' . self::CONFIG . ',uint128,uint256,bytes)', [self::configTuple($config), $amount, $nonce, $receiverAuthorizerSignature]);
    }

    public static function encodeSettle($receiver, $token)
    {
        return Abi::encodeCall('settle(address,address)', [Address::checksum($receiver), Address::checksum($token)]);
    }

    public static function encodeMulticall(array $calls)
    {
        return Abi::encodeCall('multicall(bytes[])', [array_values($calls)]);
    }

    public static function encodeTransferWithAuthorization(array $authorization, $signature)
    {
        $a = $authorization;
        return Abi::encodeCall('transferWithAuthorization(address,address,uint256,uint256,uint256,bytes32,bytes)', [Address::checksum($a['from']), Address::checksum($a['to']), Num::dec($a['value']), Num::dec($a['validAfter']), Num::dec($a['validBefore']), Hex::lower($a['nonce']), Hex::lower($signature)]);
    }

    public static function encodeAggregate3(array $calls)
    {
        return Abi::encodeCall('aggregate3((address,bool,bytes)[])', [array_map(function ($c) {
            return [Address::checksum($c['to']), false, Hex::lower($c['data'])];
        }, array_values($calls))]);
    }

    public static function encodeChannels($channelId)
    {
        return Abi::encodeCall('channels(bytes32)', [$channelId]);
    }

    public static function decodeChannels($data)
    {
        list($balance, $totalClaimed) = Abi::decode(['uint128', 'uint128'], $data);
        return ['balance' => $balance, 'totalClaimed' => $totalClaimed];
    }

    public static function encodeReceivers($receiver, $token)
    {
        return Abi::encodeCall('receivers(address,address)', [Address::checksum($receiver), Address::checksum($token)]);
    }

    public static function decodeReceivers($data)
    {
        list($claimed, $settled) = Abi::decode(['uint128', 'uint128'], $data);
        return ['totalClaimed' => $claimed, 'totalSettled' => $settled];
    }

    public static function encodeRefundNonce($channelId)
    {
        return Abi::encodeCall('refundNonce(bytes32)', [$channelId]);
    }

    public static function decodeRefundNonce($data)
    {
        return Abi::decode(['uint256'], $data)[0];
    }

    public static function encodePendingWithdrawals($channelId)
    {
        return Abi::encodeCall('pendingWithdrawals(bytes32)', [$channelId]);
    }

    public static function decodePendingWithdrawals($data)
    {
        list($amount, $initiatedAt) = Abi::decode(['uint128', 'uint40'], $data);
        return ['amount' => $amount, 'initiatedAt' => $initiatedAt];
    }

    public static function encodeGetChannelId(array $config)
    {
        return Abi::encodeCall('getChannelId(' . self::CONFIG . ')', [self::configTuple($config)]);
    }

    public static function encodeGetVoucherDigest($channelId, $maxClaimableAmount)
    {
        return Abi::encodeCall('getVoucherDigest(bytes32,uint128)', [$channelId, $maxClaimableAmount]);
    }

    public static function encodeGetRefundDigest($channelId, $nonce, $amount)
    {
        return Abi::encodeCall('getRefundDigest(bytes32,uint256,uint128)', [$channelId, $nonce, $amount]);
    }

    public static function encodeGetClaimBatchDigest(array $claims)
    {
        return Abi::encodeCall('getClaimBatchDigest(' . self::VOUCHER_CLAIMS . ')', [self::voucherClaimTuples($claims)]);
    }

    public static function decodeBytes32($data)
    {
        return Abi::decode(['bytes32'], $data)[0];
    }
}
