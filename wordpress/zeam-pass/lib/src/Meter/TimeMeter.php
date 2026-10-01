<?php

namespace ZeamPass\Meter;

use ZeamPass\Pricing;
use ZeamPass\Secp256k1;
use ZeamPass\Settlement\Store;

final class TimeMeter
{
    const NONCE_SECS = 300;
    const KEEP_NONCES = 8;
    const KEEP_LINES = 8;
    const DEFAULT_BLOCK_MS = 250;
    const DEFAULT_MAX_BLOCKS = 14400;

    public $t;
    private $clocks;
    private $lines;
    private $now;

    public function __construct(array $t, Store $clocks, Store $lines, ?callable $now = null)
    {
        $this->t = $t;
        $this->clocks = $clocks;
        $this->lines = $lines;
        $this->now = $now ?: function () {
            return (int) floor(microtime(true) * 1000);
        };
    }

    private static function whole($v)
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_float($v) && is_finite($v)) {
            return (int) ($v < 0 ? ceil($v) : floor($v));
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return self::whole((float) trim($v));
        }
        return null;
    }

    public static function options($o)
    {
        if ($o === null || $o === false) {
            return null;
        }
        if (!is_array($o)) {
            throw new \InvalidArgumentException('pass: time is { block: "0.00025", blockMs: 250 }');
        }
        $blockMicro = Pricing::microOf(array_key_exists('block', $o) ? $o['block'] : null, 'time.block');
        $blockMs = array_key_exists('blockMs', $o) && $o['blockMs'] !== null ? self::whole($o['blockMs']) : self::DEFAULT_BLOCK_MS;
        $idleMs = array_key_exists('idleMs', $o) && $o['idleMs'] !== null ? self::whole($o['idleMs']) : 0;
        $maxBlocks = array_key_exists('maxBlocks', $o) && $o['maxBlocks'] !== null ? self::whole($o['maxBlocks']) : min(self::DEFAULT_MAX_BLOCKS, intdiv(1000000000, $blockMicro));
        if (!($blockMs !== null && $blockMs >= 1 && $blockMs <= 3600000)) {
            throw new \InvalidArgumentException('pass: time.blockMs is 1 to 3600000');
        }
        if (!($idleMs !== null && $idleMs >= 0 && $idleMs <= 3600000)) {
            throw new \InvalidArgumentException('pass: time.idleMs is 0 to 3600000');
        }
        if (!($maxBlocks !== null && $maxBlocks >= 1 && $maxBlocks * $blockMicro <= 1000000000)) {
            throw new \InvalidArgumentException('pass: time.maxBlocks is 1 or more, and at most $1,000 of blocks');
        }
        return ['blockMicro' => $blockMicro, 'blockMs' => $blockMs, 'idleMs' => $idleMs, 'maxBlocks' => $maxBlocks];
    }

    public static function text(array $t, $base = '')
    {
        $idle = $t['idleMs'] > 0 ? ' and ' . $t['idleMs'] . ' ms after each' : '';
        return 'Line time: $' . Pricing::usd($t['blockMicro']) . ' per ' . $t['blockMs'] . ' ms block. buy_time {blocks} (1 to ' . $t['maxBlocks'] . '); the time is credited to the paying channel once the payment settles. Open a line: POST ' . $base . '/line {"op":"open","channelId"}, sign the message it returns with the payer key, POST {"op":"prove","channelId","nonce","signature"}. Send the credential as x-line (MCP: _meta["zeam-pass/line"]). Time burns while a call runs on the line' . $idle . '; a call stops when the time runs out. {"op":"off"}: no new calls on the line; a running call burns to its end. Unburned time comes back with a refund.';
    }

    public static function hash($credential)
    {
        return '0x' . hash('sha256', (string) $credential);
    }

    public function nowMs()
    {
        return (int) call_user_func($this->now);
    }

    public function msOf($blocks)
    {
        return (int) $blocks * $this->t['blockMs'];
    }

    public function callMs($priceMicro)
    {
        return intdiv((int) $priceMicro * $this->t['blockMs'], $this->t['blockMicro']);
    }

    public function change($channelId, callable $fn)
    {
        $id = strtolower((string) $channelId);
        $out = null;
        $this->clocks->update($id, function ($current) use ($id, $fn, &$out) {
            $rec = $current === null ? Clock::fresh($id) : Clock::normalize($current);
            $out = $fn($rec);
            return Clock::snapshot($rec);
        });
        return $out;
    }

    private function record($channelId)
    {
        $raw = $this->clocks->get(strtolower((string) $channelId));
        return $raw === null ? null : Clock::normalize($raw);
    }

    public function status($channelId)
    {
        $rec = $this->record($channelId);
        $rec = $rec === null ? Clock::fresh($channelId) : $rec;
        $now = $this->nowMs();
        $settled = Clock::settle($rec, $now, $this->t['idleMs']);
        return ['channelId' => $rec['channelId'], 'msRemaining' => $settled['balanceMs'], 'msSpent' => $settled['spentMs'], 'msReturned' => $settled['returnedMs'], 'metering' => $settled['on'], 'blockMs' => $this->t['blockMs'], 'blockUSD' => Pricing::usd($this->t['blockMicro'])];
    }

    public function credit($channelId, $ms, $key)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        return $this->change($channelId, function (array &$rec) use ($ms, $key, $now, $idle) {
            return Clock::buy($rec, $ms, (string) $key, $now, $idle);
        });
    }

    public function uncredit($channelId, $ms, $key)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        return $this->change($channelId, function (array &$rec) use ($ms, $key, $now, $idle) {
            return Clock::unbuy($rec, $ms, (string) $key, $now, $idle);
        });
    }

    public function switch($channelId, $on)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        return $this->change($channelId, function (array &$rec) use ($on, $now, $idle) {
            Clock::switch($rec, $on, $now, $idle);
            return $rec['balanceMs'];
        });
    }

    public function stop($channelId)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        return $this->change($channelId, function (array &$rec) use ($now, $idle) {
            foreach ($rec['calls'] as $c) {
                if ($c['deadline'] > $now) {
                    return false;
                }
            }
            Clock::switch($rec, false, $now, $idle);
            return true;
        });
    }

    public function begin($channelId, $id)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        $b = $this->change($channelId, function (array &$rec) use ($id, $now, $idle) {
            return Clock::begin($rec, $id, $now, $idle);
        });
        return $b['ok'] ? $b + ['started' => $this->nowMs()] : $b;
    }

    public function end($channelId, $id, $started = null, $stopped = null)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        return $this->change($channelId, function (array &$rec) use ($id, $now, $idle, $started, $stopped) {
            return Clock::end($rec, $id, $now, $idle, $started, $stopped) + ['msRemaining' => $rec['balanceMs']];
        });
    }

    public function busy($channelId)
    {
        $rec = $this->record($channelId);
        if ($rec === null) {
            return false;
        }
        $now = $this->nowMs();
        foreach ($rec['calls'] as $c) {
            if ($c['deadline'] > $now) {
                return true;
            }
        }
        return false;
    }

    public function unburnedMicro($channelId)
    {
        $rec = $this->record($channelId);
        return $rec === null ? 0 : Clock::unburnedMicro($rec, $this->nowMs(), $this->t['idleMs'], $this->t['blockMicro'], $this->t['blockMs']);
    }

    private function drop($hash)
    {
        try {
            $this->lines->update($hash, function () {
                return null;
            });
        } catch (\Throwable $e) {
            return;
        }
    }

    public function forget($channelId)
    {
        $now = $this->nowMs();
        $idle = $this->t['idleMs'];
        $lines = [];
        $returned = $this->change($channelId, function (array &$rec) use ($now, $idle, &$lines) {
            $lines = $rec['lines'];
            $rec['lines'] = [];
            return Clock::forget($rec, $now, $idle);
        });
        foreach ($lines as $h) {
            $this->drop($h);
        }
        return $returned;
    }

    public function challenge($channelId)
    {
        $nonce = bin2hex(random_bytes(16));
        $now = $this->nowMs();
        $message = Clock::lineMessage($channelId, $nonce);
        return $this->change($channelId, function (array &$rec) use ($nonce, $now, $message) {
            $live = [];
            foreach ($rec['nonces'] as $n => $until) {
                if ($until > $now) {
                    $live[(string) $n] = $until;
                }
            }
            $live = array_slice($live, -(self::KEEP_NONCES - 1), null, true);
            $live[$nonce] = $now + self::NONCE_SECS * 1000;
            $rec['nonces'] = $live;
            return ['nonce' => $nonce, 'message' => $message, 'expiresInSeconds' => self::NONCE_SECS];
        });
    }

    public function prove(array $channel, $nonce, $signature)
    {
        $channelId = strtolower((string) $channel['channelId']);
        $now = $this->nowMs();
        $n = is_scalar($nonce) ? (string) $nonce : '';
        $live = $this->change($channelId, function (array &$rec) use ($n, $now) {
            $until = array_key_exists($n, $rec['nonces']) ? $rec['nonces'][$n] : null;
            unset($rec['nonces'][$n]);
            return $until !== null && $until > $now;
        });
        if (!$live) {
            return ['ok' => false, 'status' => 400, 'code' => 'no_challenge', 'why' => 'no live challenge with that nonce. Send {"op":"open"} again.'];
        }
        try {
            $who = strtolower(Secp256k1::recoverPersonal(Clock::lineMessage($channelId, $n), is_scalar($signature) ? (string) $signature : ''));
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 400, 'code' => 'bad_signature', 'why' => 'the signature does not recover: ' . substr(\ZeamPass\message($e), 0, 80)];
        }
        $payer = strtolower(isset($channel['channelConfig']['payer']) ? (string) $channel['channelConfig']['payer'] : '');
        $auth = strtolower(isset($channel['channelConfig']['payerAuthorizer']) ? (string) $channel['channelConfig']['payerAuthorizer'] : '');
        if ($who !== $payer && $who !== $auth) {
            return ['ok' => false, 'status' => 403, 'code' => 'not_the_payer', 'why' => $who . ' is not this channel\'s payer'];
        }
        $credential = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $h = self::hash($credential);
        $this->lines->update($h, function () use ($channelId, $now) {
            return ['channelId' => $channelId, 'at' => $now];
        });
        $dropped = [];
        $idle = $this->t['idleMs'];
        $msRemaining = $this->change($channelId, function (array &$rec) use ($h, $now, $idle, &$dropped) {
            $all = array_merge($rec['lines'], [$h]);
            $dropped = array_slice($all, 0, max(0, count($all) - self::KEEP_LINES));
            $rec['lines'] = array_values(array_slice($all, -self::KEEP_LINES));
            Clock::switch($rec, true, $now, $idle);
            return $rec['balanceMs'];
        });
        foreach ($dropped as $x) {
            $this->drop($x);
        }
        return ['ok' => true, 'credential' => $credential, 'channelId' => $channelId, 'msRemaining' => $msRemaining];
    }

    public function line($credential)
    {
        if (!is_string($credential) || $credential === '') {
            return null;
        }
        $hit = $this->lines->get(self::hash($credential));
        return is_array($hit) && isset($hit['channelId']) ? (string) $hit['channelId'] : null;
    }

    public function close($credential)
    {
        $h = self::hash($credential);
        $hit = $this->lines->get($h);
        if (!is_array($hit) || !isset($hit['channelId'])) {
            return null;
        }
        $this->lines->update($h, function () {
            return null;
        });
        $this->change($hit['channelId'], function (array &$rec) use ($h) {
            $rec['lines'] = array_values(array_filter($rec['lines'], function ($x) use ($h) {
                return $x !== $h;
            }));
            return null;
        });
        return (string) $hit['channelId'];
    }
}
