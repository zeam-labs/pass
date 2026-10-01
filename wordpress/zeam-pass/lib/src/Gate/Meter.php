<?php

namespace ZeamPass\Gate;

final class Meter
{
    const FREE_PER_MONTH = 10000;
    const CREDIT_BLOCK = 2000;

    private $store;
    private $name;
    private $free;
    private $block;
    private $onEmpty;
    private $now;

    public function __construct(Store $store, $name, array $options = [])
    {
        $this->store = $store;
        $this->name = (string) $name;
        $this->free = isset($options['freePerMonth']) ? max(0, (int) $options['freePerMonth']) : self::FREE_PER_MONTH;
        $this->block = isset($options['creditBlock']) ? max(1000, (int) $options['creditBlock']) : self::CREDIT_BLOCK;
        $this->onEmpty = isset($options['onEmpty']) && $options['onEmpty'] === 'allow' ? 'allow' : 'refuse';
        $this->now = isset($options['now']) && is_callable($options['now']) ? $options['now'] : null;
    }

    public function name()
    {
        return $this->name;
    }

    public function creditBlock()
    {
        return $this->block;
    }

    public function onEmpty()
    {
        return $this->onEmpty;
    }

    private function nowMs()
    {
        return $this->now !== null ? (int) call_user_func($this->now) : (int) floor(microtime(true) * 1000);
    }

    private function key()
    {
        return 'meter-' . $this->name;
    }

    private function fresh($state)
    {
        $month = gmdate('Y-m', intdiv($this->nowMs(), 1000));
        if (!is_array($state)) {
            $state = ['month' => $month, 'used' => 0, 'credit' => 0, 'notes' => []];
        }
        $state += ['month' => $month, 'used' => 0, 'credit' => 0, 'notes' => []];
        if ($state['month'] !== $month) {
            $state['month'] = $month;
            $state['used'] = 0;
        }
        return $state;
    }

    public function usage()
    {
        $s = $this->fresh($this->store->get($this->key()));
        return ['month' => $s['month'], 'used' => (int) $s['used'], 'free' => $this->free, 'credit' => (int) $s['credit'], 'notes' => count($s['notes'])];
    }

    public function wantsCredit()
    {
        $s = $this->fresh($this->store->get($this->key()));
        return $this->lowOn($s);
    }

    private function lowOn(array $s)
    {
        return $s['used'] >= $this->free && $s['credit'] < $this->block * 0.1;
    }

    public function charge()
    {
        $onEmpty = $this->onEmpty;
        $free = $this->free;
        return $this->store->update($this->key(), function ($state) use ($onEmpty, $free) {
            $s = $this->fresh($state);
            if ($s['used'] < $free) {
                $s['used']++;
                $out = ['ok' => true, 'free' => true];
            } elseif ($s['credit'] > 0) {
                $s['credit']--;
                $s['used']++;
                $out = ['ok' => true, 'credit' => $s['credit']];
            } elseif ($onEmpty === 'allow') {
                $s['used']++;
                $out = ['ok' => true, 'unpaid' => true];
            } else {
                $out = [
                    'ok' => false,
                    'status' => 402,
                    'code' => 'gate_credit_exhausted',
                    'why' => 'This gate has no checks left this month. It admits again when the seller adds credit.',
                ];
            }
            $out['buy'] = $this->lowOn($s);
            return [$s, $out];
        });
    }

    public function addCredit($noteId, $checks)
    {
        $noteId = (string) $noteId;
        $checks = (int) $checks;
        return $this->store->update($this->key(), function ($state) use ($noteId, $checks) {
            $s = $this->fresh($state);
            if (in_array($noteId, $s['notes'], true)) {
                return [$state, ['ok' => false, 'why' => 'this note is already added']];
            }
            $s['notes'][] = $noteId;
            $s['credit'] += $checks;
            return [$s, ['ok' => true, 'credit' => $s['credit']]];
        });
    }
}
