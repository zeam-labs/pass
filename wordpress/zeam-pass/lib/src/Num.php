<?php

namespace ZeamPass;

final class Num
{
    public static function of($value)
    {
        Core::assertReady();
        if (Big::is($value)) {
            return $value;
        }
        if (is_int($value)) {
            return Big::init($value);
        }
        if (is_bool($value)) {
            return Big::init($value ? 1 : 0);
        }
        if (is_string($value)) {
            $v = trim($value);
            if (preg_match('/^0x[0-9a-fA-F]+$/D', $v)) {
                return Big::init(substr($v, 2), 16);
            }
            if (preg_match('/^-?[0-9]+$/D', $v)) {
                return Big::init($v, 10);
            }
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 9007199254740992) {
            return Big::init(sprintf('%.0f', $value), 10);
        }
        throw new \InvalidArgumentException(esc_html('not an integer: ' . (is_scalar($value) ? (string) $value : gettype($value))));
    }

    public static function dec($value)
    {
        return Big::strval(self::of($value), 10);
    }

    public static function toWord($value, $signed = false, $bits = 256)
    {
        $n = self::of($value);
        if ($bits < 8 || $bits > 256 || $bits % 8 !== 0) {
            throw new \InvalidArgumentException('integer width must be a multiple of 8 from 8 to 256');
        }
        if ($signed) {
            $lim = Big::pow(2, $bits - 1);
            if (Big::cmp($n, Big::neg($lim)) < 0 || Big::cmp($n, Big::sub($lim, 1)) > 0) {
                throw new \InvalidArgumentException(esc_html("value out of range for int$bits"));
            }
            if (Big::sign($n) < 0) {
                $n = Big::add(Big::pow(2, 256), $n);
            }
        } else {
            if (Big::sign($n) < 0 || Big::cmp($n, Big::sub(Big::pow(2, $bits), 1)) > 0) {
                throw new \InvalidArgumentException(esc_html("value out of range for uint$bits"));
            }
        }
        return self::toBytes($n, 32);
    }

    public static function toBytes($value, $length)
    {
        $n = self::of($value);
        if (Big::sign($n) < 0) {
            throw new \InvalidArgumentException('negative value');
        }
        $hex = Big::strval($n, 16);
        if (strlen($hex) % 2) {
            $hex = '0' . $hex;
        }
        if (strlen($hex) > 2 * $length) {
            throw new \InvalidArgumentException(esc_html("value does not fit in $length bytes"));
        }
        return hex2bin(str_pad($hex, 2 * $length, '0', STR_PAD_LEFT));
    }

    public static function fromBytes($bin, $signed = false, $bits = 256)
    {
        Core::assertReady();
        $hex = bin2hex($bin);
        $n = Big::init($hex === '' ? '0' : $hex, 16);
        if ($signed && strlen($bin) > 0 && (ord($bin[0]) & 0x80)) {
            $n = Big::sub($n, Big::pow(2, 8 * strlen($bin)));
        }
        return $n;
    }
}
