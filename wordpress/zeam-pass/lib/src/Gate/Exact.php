<?php

namespace ZeamPass\Gate;

use ZeamPass\Address;
use ZeamPass\BatchSettlement;
use ZeamPass\Big;
use ZeamPass\Erc20;
use ZeamPass\Hex;
use ZeamPass\Num;
use ZeamPass\Secp256k1;

final class Exact
{
    const NETWORK = 'eip155:8453';
    const MAX_TIMEOUT_SECONDS = 300;
    const VALID_BEFORE_MARGIN = 6;

    public static function requirement($amount, $payTo, array $options = [])
    {
        $req = new \stdClass();
        $req->scheme = 'exact';
        $req->network = isset($options['network']) ? (string) $options['network'] : self::NETWORK;
        $req->amount = (string) $amount;
        $req->asset = isset($options['asset']) ? (string) $options['asset'] : Erc20::USDC_BASE;
        $req->payTo = (string) $payTo;
        $req->maxTimeoutSeconds = isset($options['maxTimeoutSeconds']) ? (int) $options['maxTimeoutSeconds'] : self::MAX_TIMEOUT_SECONDS;
        $req->extra = new \stdClass();
        $req->extra->name = isset($options['assetName']) ? (string) $options['assetName'] : Erc20::USDC_BASE_NAME;
        $req->extra->version = isset($options['assetVersion']) ? (string) $options['assetVersion'] : Erc20::USDC_BASE_VERSION;
        return $req;
    }

    public static function chainId($network)
    {
        if (!is_string($network) || !preg_match('/^eip155:(\d+)/', $network, $m)) {
            throw new \InvalidArgumentException(esc_html('not an eip155 network: ' . (is_scalar($network) ? $network : gettype($network))));
        }
        return (int) $m[1];
    }

    public static function encodeHeader($value)
    {
        return Json::base64Encode(Json::encode($value));
    }

    public static function decodeHeader($value)
    {
        $raw = Json::base64Decode($value);
        if ($raw === null) {
            return null;
        }
        list($ok, $decoded) = Json::decode($raw);
        return $ok ? $decoded : null;
    }

    public static function matches($requirement, $payload)
    {
        if (!is_object($payload) || !isset($payload->x402Version) || !isset($payload->accepted) || !is_object($payload->accepted)) {
            return false;
        }
        if ($payload->x402Version === 1) {
            return isset($payload->accepted->scheme, $payload->accepted->network)
                && $payload->accepted->scheme === $requirement->scheme && $payload->accepted->network === $requirement->network;
        }
        if ($payload->x402Version !== 2) {
            return false;
        }
        $required = Json::toObject($requirement);
        $accepted = clone $payload->accepted;
        $requiredExtra = property_exists($required, 'extra') ? $required->extra : null;
        $acceptedExtra = property_exists($accepted, 'extra') ? $accepted->extra : null;
        unset($required->extra, $accepted->extra);
        if (!Json::deepEqual($required, $accepted)) {
            return false;
        }
        return $requiredExtra === null || Json::containsSubset($requiredExtra, $acceptedExtra);
    }

    public static function isAddress($value)
    {
        if (!is_string($value) || !Address::isAddress($value)) {
            return false;
        }
        return strtolower($value) === $value || Address::checksum($value) === $value;
    }

    public static function bigint($value)
    {
        if (is_int($value)) {
            return Num::of($value);
        }
        if (is_float($value) && floor($value) === $value) {
            return Num::of($value);
        }
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return Num::of(0);
        }
        if (preg_match('/^0x[0-9a-fA-F]+$/D', $v) || preg_match('/^-?[0-9]+$/D', $v)) {
            return Num::of($v);
        }
        return null;
    }

    public static function tokenDomain($requirement)
    {
        return BatchSettlement::tokenDomain($requirement->asset, $requirement->extra->name, $requirement->extra->version, self::chainId($requirement->network));
    }

    public static function authorizationMessage($authorization)
    {
        $a = is_object($authorization) ? $authorization : Json::toObject($authorization);
        foreach (['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'] as $field) {
            if (!property_exists($a, $field)) {
                return null;
            }
        }
        if (!self::isAddress($a->from) || !self::isAddress($a->to) || !Hex::isHex($a->nonce, 32)) {
            return null;
        }
        $message = ['from' => $a->from, 'to' => $a->to, 'nonce' => $a->nonce];
        foreach (['value', 'validAfter', 'validBefore'] as $field) {
            $n = self::bigint($a->{$field});
            if ($n === null || Big::sign($n) < 0 || Big::cmp($n, Big::sub(Big::pow(2, 256), 1)) > 0) {
                return null;
            }
            $message[$field] = Big::strval($n, 10);
        }
        return [
            'from' => $message['from'],
            'to' => $message['to'],
            'value' => $message['value'],
            'validAfter' => $message['validAfter'],
            'validBefore' => $message['validBefore'],
            'nonce' => $message['nonce'],
        ];
    }

    public static function verify($payload, $requirement, $nowSeconds)
    {
        if ($payload->x402Version !== 2) {
            return 'No facilitator registered for x402 version: ' . $payload->x402Version;
        }
        $inner = isset($payload->payload) && is_object($payload->payload) ? $payload->payload : null;
        $auth = $inner !== null && isset($inner->authorization) && is_object($inner->authorization) ? $inner->authorization : null;
        if ($auth === null || !isset($inner->signature) || !is_string($inner->signature)) {
            return 'invalid_exact_evm_payload';
        }
        if (!isset($payload->accepted->scheme) || $payload->accepted->scheme !== 'exact' || $requirement->scheme !== 'exact') {
            return 'invalid_exact_evm_scheme';
        }
        if (empty($requirement->extra->name) || empty($requirement->extra->version)) {
            return 'invalid_exact_evm_missing_eip712_domain';
        }
        if (!isset($payload->accepted->network) || $payload->accepted->network !== $requirement->network) {
            return 'invalid_exact_evm_network_mismatch';
        }
        $message = self::authorizationMessage($auth);
        $sig = $inner->signature;
        $sigBody = strncmp($sig, '0x', 2) === 0 ? substr($sig, 2) : $sig;
        if ($message === null || strlen($sigBody) !== 130 || !ctype_xdigit($sigBody)) {
            return 'invalid_exact_evm_signature';
        }
        try {
            $signer = Secp256k1::recoverTypedData(self::tokenDomain($requirement), BatchSettlement::TRANSFER_AUTHORIZATION_TYPES, 'TransferWithAuthorization', $message, '0x' . $sigBody);
        } catch (\InvalidArgumentException $e) {
            return 'invalid_exact_evm_signature';
        }
        if (!Address::equals($signer, $message['from'])) {
            return 'invalid_exact_evm_signature';
        }
        if (strtolower($message['to']) !== strtolower((string) $requirement->payTo)) {
            return 'invalid_exact_evm_recipient_mismatch';
        }
        if (Big::cmp(Big::init($message['validBefore'], 10), Big::add(Big::init((int) $nowSeconds), self::VALID_BEFORE_MARGIN)) < 0) {
            return 'invalid_exact_evm_payload_authorization_valid_before';
        }
        if (Big::cmp(Big::init($message['validAfter'], 10), Big::init((int) $nowSeconds)) > 0) {
            return 'invalid_exact_evm_payload_authorization_valid_after';
        }
        $amount = self::bigint($requirement->amount);
        if ($amount === null || Big::cmp(Big::init($message['value'], 10), $amount) !== 0) {
            return 'invalid_exact_evm_payload_authorization_value_mismatch';
        }
        $v = ord(Hex::toBin('0x' . substr($sigBody, 128, 2)));
        if (($v !== 27 && $v !== 28) || !Secp256k1::isLowS('0x' . $sigBody)) {
            return 'invalid_exact_evm_signature_not_accepted_by_token';
        }
        return null;
    }

    public static function authorize($key, $requirement, $nowSeconds, $nonce)
    {
        $from = Secp256k1::privateKeyToAddress($key);
        $authorization = new \stdClass();
        $authorization->from = $from;
        $authorization->to = Address::checksum($requirement->payTo);
        $authorization->value = $requirement->amount;
        $authorization->validAfter = '0';
        $authorization->validBefore = (string) ((int) $nowSeconds + (int) $requirement->maxTimeoutSeconds);
        $authorization->nonce = strtolower($nonce);
        $inner = new \stdClass();
        $inner->authorization = $authorization;
        $inner->signature = Secp256k1::signTypedData($key, self::tokenDomain($requirement), BatchSettlement::TRANSFER_AUTHORIZATION_TYPES, 'TransferWithAuthorization', self::authorizationMessage($authorization));
        return $inner;
    }

    public static function paymentPayload($paymentRequired, $requirement, $inner)
    {
        $payload = new \stdClass();
        $payload->x402Version = $paymentRequired->x402Version;
        $payload->payload = $inner;
        if (property_exists($paymentRequired, 'extensions')) {
            $payload->extensions = $paymentRequired->extensions;
        }
        if (property_exists($paymentRequired, 'resource')) {
            $payload->resource = $paymentRequired->resource;
        }
        $payload->accepted = $requirement;
        return $payload;
    }
}
