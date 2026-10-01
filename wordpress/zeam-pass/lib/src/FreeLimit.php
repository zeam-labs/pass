<?php

namespace ZeamPass;

use ZeamPass\Settlement\ArrayCache;
use ZeamPass\Settlement\Cache;

final class FreeLimit
{
    private $perHour;
    private $now;
    private $counts;

    public function __construct($perHour, ?callable $now = null, ?Cache $counts = null)
    {
        $this->perHour = $perHour;
        $this->now = $now ?: function () {
            return (int) floor(microtime(true) * 1000);
        };
        $this->counts = $counts ?: new ArrayCache();
    }

    public static function window($nowMs)
    {
        return (int) floor($nowMs / Pricing::HOUR_MS);
    }

    public static function key($window, $tool, $who)
    {
        return 'zeam_pass_free' . "\n" . $window . "\n" . $tool . "\n" . ($who === null ? '' : $who);
    }

    public function take($tool, $who)
    {
        if (!(is_numeric($this->perHour) && $this->perHour >= 1)) {
            return ['ok' => true];
        }
        $now = (int) call_user_func($this->now);
        $w = self::window($now);
        $key = self::key($w, (string) $tool, $who);
        $used = (int) $this->counts->get($key);
        $end = ($w + 1) * Pricing::HOUR_MS;
        if ($used >= $this->perHour) {
            return ['ok' => false, 'retryAfter' => max(1, (int) ceil(($end - $now) / 1000))];
        }
        $this->counts->set($key, $used + 1, max(1, (int) ceil(($end - $now) / 1000)) + 60);
        return ['ok' => true];
    }

    public static function refusal($perHour, $retryAfter)
    {
        return ['error' => 'free_limit', 'message' => $perHour . ' free calls an hour per address. Retry in ' . $retryAfter . 's.', 'retry_after_seconds' => $retryAfter];
    }
}
