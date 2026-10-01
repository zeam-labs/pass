<?php

namespace ZeamPass;

final class Hex
{
    public static function isHex($value, $bytes = null)
    {
        if (!is_string($value) || !preg_match('/^0x([0-9a-fA-F]{2})*$/D', $value)) {
            return false;
        }
        return $bytes === null || strlen($value) === 2 + 2 * $bytes;
    }

    public static function toBin($hex)
    {
        if (!is_string($hex)) {
            throw new \InvalidArgumentException('hex value must be a string');
        }
        $body = strncasecmp($hex, '0x', 2) === 0 ? substr($hex, 2) : $hex;
        if ($body === '') {
            return '';
        }
        if (strlen($body) % 2 !== 0 || !ctype_xdigit($body)) {
            throw new \InvalidArgumentException(esc_html('not an even-length hex string: ' . substr($hex, 0, 80)));
        }
        return hex2bin($body);
    }

    public static function fromBin($bin)
    {
        return '0x' . bin2hex($bin);
    }

    public static function lower($hex)
    {
        return self::fromBin(self::toBin($hex));
    }

    public static function concat(array $hexes)
    {
        $out = '';
        foreach ($hexes as $h) {
            $out .= self::toBin($h);
        }
        return self::fromBin($out);
    }

    public static function equals($a, $b)
    {
        return strtolower(self::lower($a)) === strtolower(self::lower($b));
    }
}
