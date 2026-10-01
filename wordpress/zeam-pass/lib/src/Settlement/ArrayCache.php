<?php

namespace ZeamPass\Settlement;

final class ArrayCache implements Cache
{
    private $items = [];
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    private function now()
    {
        return $this->clock ? (float) call_user_func($this->clock) : microtime(true) * 1000;
    }

    public function get($key)
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        list($value, $until) = $this->items[$key];
        if ($until < $this->now()) {
            unset($this->items[$key]);
            return null;
        }
        return $value;
    }

    public function set($key, $value, $ttlSeconds)
    {
        $this->items[$key] = [$value, $this->now() + 1000 * (float) $ttlSeconds];
    }
}
