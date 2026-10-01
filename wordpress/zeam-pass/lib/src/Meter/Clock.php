<?php

namespace ZeamPass\Meter;

final class Clock
{
    const KEEP_CREDITED = 64;

    public static function fresh($channelId)
    {
        return ['channelId' => strtolower((string) $channelId), 'balanceMs' => 0, 'spentMs' => 0, 'returnedMs' => 0, 'on' => false, 'since' => null, 'lastActive' => null, 'calls' => [], 'credited' => [], 'nonces' => [], 'lines' => []];
    }

    public static function covered(array $intervals)
    {
        $sorted = array_values(array_filter($intervals, function ($x) {
            return $x[1] > $x[0];
        }));
        usort($sorted, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
        });
        $total = 0;
        $end = null;
        foreach ($sorted as $x) {
            list($s, $e) = $x;
            if ($end === null || $s >= $end) {
                $total += $e - $s;
                $end = $e;
            } elseif ($e > $end) {
                $total += $e - $end;
                $end = $e;
            }
        }
        return $total;
    }

    private static function expire(array &$rec, $now)
    {
        foreach ($rec['calls'] as $id => $c) {
            if ($c['deadline'] <= $now) {
                unset($rec['calls'][$id]);
            }
        }
    }

    public static function settle(array &$rec, $now, $idleMs = 0)
    {
        if ($rec['since'] === null) {
            $rec['since'] = $now;
            self::expire($rec, $now);
            return $rec;
        }
        $since = $rec['since'];
        $spans = [];
        foreach ($rec['calls'] as $c) {
            $spans[] = [max($c['start'], $since), min($now, $c['deadline'])];
        }
        if ($rec['on'] && $idleMs > 0 && $rec['lastActive'] !== null) {
            $spans[] = [max($rec['lastActive'], $since), min($now, $rec['lastActive'] + $idleMs)];
        }
        $burned = min($rec['balanceMs'], self::covered($spans));
        $rec['balanceMs'] -= $burned;
        $rec['spentMs'] += $burned;
        $rec['since'] = $now;
        self::expire($rec, $now);
        return $rec;
    }

    public static function remaining(array $rec, $now, $idleMs = 0)
    {
        return self::settle($rec, $now, $idleMs)['balanceMs'];
    }

    public static function switch(array &$rec, $on, $now, $idleMs = 0)
    {
        self::settle($rec, $now, $idleMs);
        $rec['on'] = (bool) $on;
        $rec['since'] = $now;
        if ($on) {
            $rec['lastActive'] = $now;
        }
        return $rec;
    }

    public static function begin(array &$rec, $id, $now, $idleMs = 0)
    {
        self::settle($rec, $now, $idleMs);
        if (!$rec['on']) {
            return ['ok' => false, 'code' => 'meter_off'];
        }
        if ($rec['balanceMs'] <= 0) {
            return ['ok' => false, 'code' => 'out_of_time'];
        }
        $deadline = $now + $rec['balanceMs'];
        $rec['calls'][(string) $id] = ['start' => $now, 'deadline' => $deadline];
        $rec['lastActive'] = $now;
        return ['ok' => true, 'deadline' => $deadline];
    }

    public static function end(array &$rec, $id, $now, $idleMs = 0, $start = null, $stop = null)
    {
        $id = (string) $id;
        $c = isset($rec['calls'][$id]) ? $rec['calls'][$id] : null;
        $ran = min($stop === null ? $now : $stop, $now);
        $at = $rec['since'] === null ? $ran : max($ran, $rec['since']);
        if ($c !== null && $start !== null && $start > $c['start'] && $ran <= $c['deadline']) {
            $c['start'] = min($start, $ran);
            $rec['calls'][$id]['start'] = $c['start'];
        }
        self::settle($rec, $at, $idleMs);
        unset($rec['calls'][$id]);
        $rec['lastActive'] = $rec['lastActive'] === null ? $ran : max($rec['lastActive'], $ran);
        return ['elapsedMs' => $c !== null ? $ran - $c['start'] : 0, 'over' => $c === null || $ran > $c['deadline']];
    }

    public static function buy(array &$rec, $ms, $key, $now, $idleMs = 0)
    {
        if (in_array((string) $key, $rec['credited'], true)) {
            return false;
        }
        self::settle($rec, $now, $idleMs);
        $rec['balanceMs'] += (int) $ms;
        $credited = $rec['credited'];
        $credited[] = (string) $key;
        $rec['credited'] = array_values(array_slice($credited, -self::KEEP_CREDITED));
        return true;
    }

    public static function unbuy(array &$rec, $ms, $key, $now, $idleMs = 0)
    {
        $key = (string) $key;
        if (!in_array($key, $rec['credited'], true)) {
            return 0;
        }
        self::settle($rec, $now, $idleMs);
        $taken = min((int) $ms, $rec['balanceMs']);
        $rec['balanceMs'] -= $taken;
        $rec['credited'] = array_values(array_filter($rec['credited'], function ($k) use ($key) {
            return $k !== $key;
        }));
        return $taken;
    }

    public static function unburnedMicro(array $rec, $now, $idleMs, $blockMicro, $blockMs)
    {
        $left = self::remaining($rec, $now, $idleMs);
        return intdiv($left * (int) $blockMicro + (int) $blockMs - 1, (int) $blockMs);
    }

    public static function forget(array &$rec, $now, $idleMs = 0)
    {
        self::settle($rec, $now, $idleMs);
        $returned = $rec['balanceMs'];
        $rec['returnedMs'] += $returned;
        $rec['balanceMs'] = 0;
        $rec['on'] = false;
        $rec['calls'] = [];
        return $returned;
    }

    public static function lineMessage($channelId, $nonce)
    {
        return "ZEAM Pass line\nchannel: " . strtolower((string) $channelId) . "\nnonce: " . $nonce;
    }

    public static function normalize($rec)
    {
        $out = self::fresh(is_array($rec) && isset($rec['channelId']) ? $rec['channelId'] : '');
        if (!is_array($rec)) {
            return $out;
        }
        foreach (['balanceMs', 'spentMs', 'returnedMs'] as $k) {
            $out[$k] = isset($rec[$k]) ? (int) $rec[$k] : 0;
        }
        $out['on'] = !empty($rec['on']);
        foreach (['since', 'lastActive'] as $k) {
            $out[$k] = isset($rec[$k]) ? (int) $rec[$k] : null;
        }
        foreach (['calls', 'nonces'] as $k) {
            $out[$k] = isset($rec[$k]) && is_array($rec[$k]) ? $rec[$k] : [];
        }
        foreach (['credited', 'lines'] as $k) {
            $out[$k] = isset($rec[$k]) && is_array($rec[$k]) ? array_values($rec[$k]) : [];
        }
        return $out;
    }

    public static function snapshot(array $rec)
    {
        return ['channelId' => $rec['channelId'], 'balanceMs' => $rec['balanceMs'], 'spentMs' => $rec['spentMs'], 'returnedMs' => $rec['returnedMs'], 'on' => $rec['on'], 'since' => $rec['since'], 'lastActive' => $rec['lastActive'], 'calls' => $rec['calls'] === [] ? new \stdClass() : $rec['calls'], 'credited' => $rec['credited'], 'nonces' => $rec['nonces'] === [] ? new \stdClass() : $rec['nonces'], 'lines' => $rec['lines']];
    }
}
