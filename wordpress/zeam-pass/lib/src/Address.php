<?php

namespace ZeamPass;

final class Address
{
    const ZERO = '0x0000000000000000000000000000000000000000';

    public static function isAddress($value)
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/D', $value) === 1;
    }

    public static function checksum($address)
    {
        if (!self::isAddress($address)) {
            throw new \InvalidArgumentException(esc_html('not an address: ' . (is_scalar($address) ? (string) $address : gettype($address))));
        }
        $lower = strtolower(substr($address, 2));
        $hash = bin2hex(Keccak::hash($lower));
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $c = $lower[$i];
            $out .= (ctype_alpha($c) && hexdec($hash[$i]) >= 8) ? strtoupper($c) : $c;
        }
        return $out;
    }

    public static function isChecksummed($address)
    {
        return self::isAddress($address) && self::checksum($address) === $address;
    }

    public static function equals($a, $b)
    {
        return self::isAddress($a) && self::isAddress($b) && strtolower($a) === strtolower($b);
    }

    public static function fromPublicKey($uncompressed)
    {
        $bin = Hex::toBin($uncompressed);
        if (strlen($bin) === 65 && ord($bin[0]) === 4) {
            $bin = substr($bin, 1);
        }
        if (strlen($bin) !== 64) {
            throw new \InvalidArgumentException('an uncompressed public key is 64 or 65 bytes');
        }
        return self::checksum(Hex::fromBin(substr(Keccak::hash($bin), 12)));
    }
}
