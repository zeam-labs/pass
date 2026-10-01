<?php

if (!defined('ABSPATH')) {
    exit;
}

const ZEAM_PASS_SECRETS = 'zeam_pass_secrets';

function zeam_pass_has_dedicated_key()
{
    return defined('ZEAM_PASS_KEY') && is_string(ZEAM_PASS_KEY) && strlen(ZEAM_PASS_KEY) >= 32;
}

function zeam_pass_wrapping_keys()
{
    if (!function_exists('sodium_crypto_secretbox')) {
        return [];
    }
    $keys = [];
    if (zeam_pass_has_dedicated_key()) {
        $keys[] = sodium_crypto_generichash('zeam-pass-keys|dedicated|' . ZEAM_PASS_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
    $salts = defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') ? AUTH_KEY . SECURE_AUTH_KEY : wp_salt('auth') . wp_salt('secure_auth');
    $keys[] = sodium_crypto_generichash('zeam-pass-keys|wordpress|' . $salts, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    return $keys;
}

function zeam_pass_seal($plain)
{
    $keys = zeam_pass_wrapping_keys();
    if ($keys === []) {
        throw new RuntimeException('ZEAM Pass needs the PHP sodium extension to keep its keys');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, $keys[0]));
}

function zeam_pass_open_sealed($sealed)
{
    if (!is_string($sealed) || strpos($sealed, 'v1:') !== 0) {
        return [null, null];
    }
    $raw = base64_decode(substr($sealed, 3), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return [null, null];
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    foreach (zeam_pass_wrapping_keys() as $i => $key) {
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        if ($plain !== false) {
            return [$plain, $i];
        }
    }
    return [null, null];
}

function zeam_pass_secrets()
{
    $all = get_option(ZEAM_PASS_SECRETS, []);
    return is_array($all) ? $all : [];
}

function zeam_pass_store_secrets(array $all)
{
    if (get_option(ZEAM_PASS_SECRETS, null) === null) {
        add_option(ZEAM_PASS_SECRETS, $all, '', 'no');
    } else {
        update_option(ZEAM_PASS_SECRETS, $all, false);
    }
}

function zeam_pass_secret($which)
{
    static $open = [];
    if (array_key_exists($which, $open)) {
        return $open[$which];
    }
    $all = zeam_pass_secrets();
    if (!isset($all[$which]['sealed'])) {
        return null;
    }
    list($plain, $index) = zeam_pass_open_sealed($all[$which]['sealed']);
    if ($plain === null) {
        return null;
    }
    if ($index > 0 && zeam_pass_has_dedicated_key()) {
        $all[$which]['sealed'] = zeam_pass_seal($plain);
        zeam_pass_store_secrets($all);
    }
    $open[$which] = $plain;
    return $plain;
}

function zeam_pass_secret_state($which)
{
    $all = zeam_pass_secrets();
    if (!isset($all[$which]['sealed'])) {
        return 'missing';
    }
    return zeam_pass_secret($which) === null ? 'locked' : 'ok';
}

function zeam_pass_secret_address($which)
{
    $all = zeam_pass_secrets();
    return isset($all[$which]['address']) && is_string($all[$which]['address']) ? $all[$which]['address'] : '';
}

function zeam_pass_new_private_key()
{
    for ($i = 0; $i < 16; $i++) {
        $key = '0x' . bin2hex(random_bytes(32));
        try {
            return [$key, \ZeamPass\Secp256k1::privateKeyToAddress($key)];
        } catch (\InvalidArgumentException $e) {
            continue;
        }
    }
    throw new RuntimeException('could not generate a key');
}

function zeam_pass_ensure_secret($which)
{
    $all = zeam_pass_secrets();
    if (isset($all[$which]['sealed'])) {
        return zeam_pass_secret_state($which) === 'ok';
    }
    if (!\ZeamPass\Core::ready() || zeam_pass_wrapping_keys() === []) {
        return false;
    }
    list($key, $address) = zeam_pass_new_private_key();
    $all[$which] = ['sealed' => zeam_pass_seal($key), 'address' => $address, 'created' => gmdate('c')];
    zeam_pass_store_secrets($all);
    return true;
}

function zeam_pass_ensure_keys()
{
    zeam_pass_ensure_secret('settle');
    if (in_array(zeam_pass_mode(), ['gate', 'both'], true)) {
        zeam_pass_ensure_secret('credit');
    }
}
