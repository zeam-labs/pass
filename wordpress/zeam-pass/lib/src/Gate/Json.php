<?php

namespace ZeamPass\Gate;

final class Json
{
    const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    public static function encode($value)
    {
        $out = json_encode($value, self::FLAGS);
        if ($out === false) {
            throw new \InvalidArgumentException(esc_html('value is not JSON: ' . json_last_error_msg()));
        }
        return $out;
    }

    public static function decode($text)
    {
        if (!is_string($text) || $text === '') {
            return [false, null];
        }
        $value = json_decode($text, false, 64);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, null];
        }
        return [true, $value];
    }

    public static function toObject($value)
    {
        return json_decode(self::encode($value), false);
    }

    public static function base64Encode($text)
    {
        return base64_encode((string) $text);
    }

    public static function base64Decode($text)
    {
        if (!is_string($text) || !preg_match('#^[A-Za-z0-9+/]*={0,2}$#D', $text)) {
            return null;
        }
        $body = rtrim($text, '=');
        if (strlen($body) % 4 === 1) {
            return null;
        }
        $out = base64_decode($body . str_repeat('=', (4 - strlen($body) % 4) % 4), true);
        return $out === false ? null : $out;
    }

    public static function base64UrlDecode($text)
    {
        $text = (string) $text;
        $cut = strpos($text, '=');
        if ($cut !== false) {
            $text = substr($text, 0, $cut);
        }
        $clean = preg_replace('#[^A-Za-z0-9+/]#', '', strtr($text, '-_', '+/'));
        if (strlen($clean) % 4 === 1) {
            $clean = substr($clean, 0, -1);
        }
        $out = base64_decode($clean . str_repeat('=', (4 - strlen($clean) % 4) % 4), false);
        return $out === false ? '' : $out;
    }

    public static function canonical($value)
    {
        return self::encode(self::sorted($value));
    }

    private static function sorted($value)
    {
        if (is_object($value)) {
            $vars = get_object_vars($value);
            ksort($vars, SORT_STRING);
            $out = new \stdClass();
            foreach ($vars as $k => $v) {
                $out->{$k} = self::sorted($v);
            }
            return $out;
        }
        if (is_array($value)) {
            return array_map([self::class, 'sorted'], $value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9007199254740992) {
            return (int) $value;
        }
        return $value;
    }

    public static function deepEqual($a, $b)
    {
        return self::canonical($a) === self::canonical($b);
    }

    public static function containsSubset($expected, $actual)
    {
        if (!is_object($expected)) {
            return self::deepEqual($expected, $actual);
        }
        if (!is_object($actual)) {
            return false;
        }
        foreach (get_object_vars($expected) as $k => $v) {
            if (!property_exists($actual, $k)) {
                return false;
            }
            if (!self::containsSubset($v, $actual->{$k})) {
                return false;
            }
        }
        return true;
    }
}
