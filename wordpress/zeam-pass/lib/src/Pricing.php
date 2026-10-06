<?php

namespace ZeamPass;

final class Pricing
{
    const HOUR_MS = 3600000;
    const NO_PRICE = 'this tool has no price; set price, prices[tool] or free';
    const BAD_UNITS = 'units is a whole number, 0 or more';

    private static function shown($value)
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }
        if (is_float($value) && !is_finite($value)) {
            return 'null';
        }
        if ($value === null) {
            return 'null';
        }
        $out = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $out === false ? '""' : $out;
    }

    private static function text($price)
    {
        if (is_int($price)) {
            return (string) $price;
        }
        if (is_float($price)) {
            return is_finite($price) ? self::shown($price) : '';
        }
        if (is_string($price)) {
            return (string) preg_replace('/^\$/', '', trim($price));
        }
        if ($price === null) {
            return '';
        }
        return is_bool($price) ? ($price ? 'true' : 'false') : '[object]';
    }

    public static function microOf($price, $what = 'price')
    {
        $s = self::text($price);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/D', $s, $m)) {
            throw new \InvalidArgumentException(esc_html($what . ' is USD with up to six decimals, like "0.02"; got ' . self::shown($price)));
        }
        $whole = ltrim($m[1], '0');
        $frac = isset($m[2]) ? (int) str_pad($m[2], 6, '0') : 0;
        if (strlen($whole) > 4) {
            throw new \InvalidArgumentException(esc_html($what . ' is from $0.000001 to $1,000'));
        }
        $micro = (int) $whole * 1000000 + $frac;
        if ($micro < 1 || $micro > 1000000000) {
            throw new \InvalidArgumentException(esc_html($what . ' is from $0.000001 to $1,000'));
        }
        return $micro;
    }

    public static function usd($micro)
    {
        $s = rtrim(rtrim(sprintf('%.6f', $micro / 1e6), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private static function blank($v)
    {
        return $v === null || $v === '';
    }

    private static function isObject($spec)
    {
        return is_array($spec) && ($spec === [] || array_keys($spec) !== range(0, count($spec) - 1));
    }

    public static function priceOf($spec, $fallbackMicro = null)
    {
        $fallback = is_int($fallbackMicro) || is_float($fallbackMicro) ? $fallbackMicro : null;
        if (self::blank($spec)) {
            if (!($fallback !== null && $fallback >= 1)) {
                throw new \InvalidArgumentException(esc_html(self::NO_PRICE));
            }
            return ['micro' => (int) $fallback, 'unitMicro' => null];
        }
        if (self::isObject($spec)) {
            $price = array_key_exists('price', $spec) ? $spec['price'] : null;
            $micro = self::blank($price) ? $fallback : self::microOf($price);
            if (!($micro !== null && $micro >= 1)) {
                throw new \InvalidArgumentException(esc_html(self::NO_PRICE));
            }
            $unit = array_key_exists('unit', $spec) ? $spec['unit'] : null;
            if (self::blank($unit)) {
                return ['micro' => (int) $micro, 'unitMicro' => null];
            }
            $unitMicro = self::microOf($unit, 'unit');
            if ($unitMicro > $micro) {
                throw new \InvalidArgumentException('a unit costs at most the price the call reserves');
            }
            return ['micro' => (int) $micro, 'unitMicro' => $unitMicro];
        }
        return ['micro' => self::microOf($spec), 'unitMicro' => null];
    }

    public static function units($units)
    {
        if (is_float($units) && is_finite($units) && floor($units) === $units && $units >= 0 && $units <= 9007199254740991) {
            return (int) $units;
        }
        if (!is_int($units) || $units < 0 || $units > 9007199254740991) {
            throw new \InvalidArgumentException(esc_html(self::BAD_UNITS));
        }
        return $units;
    }

    public static function charge(array $price, $units)
    {
        if (!isset($price['unitMicro']) || $price['unitMicro'] === null) {
            return (int) $price['micro'];
        }
        $n = self::units($units);
        $micro = (int) $price['micro'];
        $unit = (int) $price['unitMicro'];
        if ($n > intdiv($micro, $unit)) {
            return $micro;
        }
        return min($micro, $n * $unit);
    }

    public static function describe($price, array $opts = [])
    {
        if (!empty($opts['free'])) {
            $perHour = isset($opts['perHour']) ? $opts['perHour'] : null;
            return $perHour ? ['usd' => '0', 'per' => 'call', 'free' => true, 'perHour' => $perHour] : ['usd' => '0', 'per' => 'call', 'free' => true];
        }
        if (!empty($opts['varies'])) {
            return ['per' => 'call', 'varies' => true];
        }
        if (isset($price['unitMicro']) && $price['unitMicro'] !== null) {
            return ['usd' => self::usd($price['unitMicro']), 'per' => 'unit', 'upTo' => self::usd($price['micro'])];
        }
        return ['usd' => self::usd($price['micro']), 'per' => 'call'];
    }

    public static function pricing(array $price)
    {
        if (!empty($price['ms'])) {
            return '$' . self::usd($price['micro']) . ' for ' . $price['ms'] . ' ms of line time. Time you do not burn comes back with a refund.';
        }
        if (isset($price['unitMicro']) && $price['unitMicro'] !== null) {
            return 'Up to $' . self::usd($price['micro']) . ' per call, reserved; charged $' . self::usd($price['unitMicro']) . ' per unit the call reports, at most the reserve. A failed call is not charged.';
        }
        return '$' . self::usd($price['micro']) . ' per call. A failed call is not charged.';
    }
}
