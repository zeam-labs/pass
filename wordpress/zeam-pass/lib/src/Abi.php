<?php

namespace ZeamPass;

final class Abi
{
    private static $cache = [];

    public static function parseType($type)
    {
        $type = trim((string) $type);
        if (isset(self::$cache[$type])) {
            return self::$cache[$type];
        }
        $node = self::parseAt($type);
        self::$cache[$type] = $node;
        return $node;
    }

    private static function parseAt($type)
    {
        if (preg_match('/^(.*)\[(\d*)\]$/s', $type, $m) && self::balanced($m[1])) {
            return ['kind' => 'array', 'of' => self::parseAt($m[1]), 'length' => $m[2] === '' ? null : (int) $m[2]];
        }
        if ($type !== '' && $type[0] === '(') {
            if (substr($type, -1) !== ')') {
                throw new \InvalidArgumentException(esc_html("malformed tuple type: $type"));
            }
            $inner = substr($type, 1, -1);
            $parts = $inner === '' ? [] : self::splitTop($inner);
            return ['kind' => 'tuple', 'components' => array_map([self::class, 'parseAt'], $parts)];
        }
        if ($type === 'address' || $type === 'bool' || $type === 'string' || $type === 'bytes') {
            return ['kind' => $type];
        }
        if ($type === 'uint' || $type === 'int') {
            return ['kind' => $type, 'bits' => 256];
        }
        if (preg_match('/^(u?int)(\d+)$/', $type, $m)) {
            $bits = (int) $m[2];
            if ($bits < 8 || $bits > 256 || $bits % 8 !== 0) {
                throw new \InvalidArgumentException(esc_html("invalid integer type: $type"));
            }
            return ['kind' => $m[1], 'bits' => $bits];
        }
        if (preg_match('/^bytes(\d+)$/', $type, $m)) {
            $size = (int) $m[1];
            if ($size < 1 || $size > 32) {
                throw new \InvalidArgumentException(esc_html("invalid fixed bytes type: $type"));
            }
            return ['kind' => 'fixedbytes', 'size' => $size];
        }
        throw new \InvalidArgumentException(esc_html("unsupported ABI type: $type"));
    }

    private static function balanced($s)
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    public static function splitTop($s)
    {
        $out = [];
        $depth = 0;
        $cur = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = trim($cur);
        return $out;
    }

    public static function canonical($node)
    {
        switch ($node['kind']) {
            case 'uint':
            case 'int':
                return $node['kind'] . $node['bits'];
            case 'fixedbytes':
                return 'bytes' . $node['size'];
            case 'array':
                return self::canonical($node['of']) . '[' . ($node['length'] === null ? '' : $node['length']) . ']';
            case 'tuple':
                return '(' . implode(',', array_map([self::class, 'canonical'], $node['components'])) . ')';
            default:
                return $node['kind'];
        }
    }

    public static function parseSignature($signature)
    {
        if (!preg_match('/^\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\((.*)\)\s*$/s', $signature, $m)) {
            throw new \InvalidArgumentException(esc_html("malformed function signature: $signature"));
        }
        $params = trim($m[2]) === '' ? [] : self::splitTop($m[2]);
        $nodes = array_map([self::class, 'parseType'], $params);
        $canonical = $m[1] . '(' . implode(',', array_map([self::class, 'canonical'], $nodes)) . ')';
        return ['name' => $m[1], 'types' => $params, 'nodes' => $nodes, 'canonical' => $canonical];
    }

    public static function selector($signature)
    {
        return Keccak::selector(self::parseSignature($signature)['canonical']);
    }

    public static function encodeCall($signature, array $args = [])
    {
        $sig = self::parseSignature($signature);
        if (count($args) !== count($sig['nodes'])) {
            throw new \InvalidArgumentException(esc_html($sig['name'] . ' takes ' . count($sig['nodes']) . ' arguments, got ' . count($args)));
        }
        return Keccak::selector($sig['canonical']) . bin2hex(self::encodeNodes($sig['nodes'], array_values($args)));
    }

    public static function encode(array $types, array $values)
    {
        $nodes = array_map([self::class, 'parseType'], $types);
        if (count($nodes) !== count($values)) {
            throw new \InvalidArgumentException('type and value counts differ');
        }
        return Hex::fromBin(self::encodeNodes($nodes, array_values($values)));
    }

    public static function isDynamic($node)
    {
        switch ($node['kind']) {
            case 'bytes':
            case 'string':
                return true;
            case 'array':
                return $node['length'] === null || self::isDynamic($node['of']);
            case 'tuple':
                foreach ($node['components'] as $c) {
                    if (self::isDynamic($c)) {
                        return true;
                    }
                }
                return false;
            default:
                return false;
        }
    }

    private static function headSize($node)
    {
        if (self::isDynamic($node)) {
            return 32;
        }
        if ($node['kind'] === 'array') {
            return $node['length'] * self::headSize($node['of']);
        }
        if ($node['kind'] === 'tuple') {
            $n = 0;
            foreach ($node['components'] as $c) {
                $n += self::headSize($c);
            }
            return $n;
        }
        return 32;
    }

    private static function encodeNodes(array $nodes, array $values)
    {
        $headLen = 0;
        foreach ($nodes as $n) {
            $headLen += self::headSize($n);
        }
        $head = '';
        $tail = '';
        foreach ($nodes as $i => $node) {
            if (!array_key_exists($i, $values)) {
                throw new \InvalidArgumentException(esc_html('missing value at position ' . $i));
            }
            $enc = self::encodeValue($node, $values[$i]);
            if (self::isDynamic($node)) {
                $head .= Num::toWord($headLen + strlen($tail));
                $tail .= $enc;
            } else {
                $head .= $enc;
            }
        }
        return $head . $tail;
    }

    public static function encodeValue($node, $value)
    {
        switch ($node['kind']) {
            case 'uint':
                return Num::toWord($value, false, $node['bits']);
            case 'int':
                return Num::toWord($value, true, $node['bits']);
            case 'bool':
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('bool value must be true or false');
                }
                return str_repeat("\0", 31) . ($value ? "\x01" : "\x00");
            case 'address':
                if (!Address::isAddress($value)) {
                    throw new \InvalidArgumentException(esc_html('not an address: ' . (is_scalar($value) ? (string) $value : gettype($value))));
                }
                $body = substr($value, 2);
                if (strtolower($body) !== $body && strtoupper($body) !== $body && Address::checksum($value) !== $value) {
                    throw new \InvalidArgumentException(esc_html("address has a bad checksum: $value"));
                }
                return str_repeat("\0", 12) . hex2bin($body);
            case 'fixedbytes':
                $bin = Hex::toBin($value);
                if (strlen($bin) !== $node['size']) {
                    throw new \InvalidArgumentException(esc_html('bytes' . $node['size'] . ' value has ' . strlen($bin) . ' bytes'));
                }
                return str_pad($bin, 32, "\0", STR_PAD_RIGHT);
            case 'bytes':
            case 'string':
                $bin = $node['kind'] === 'bytes' ? Hex::toBin($value) : (string) $value;
                $pad = (32 - strlen($bin) % 32) % 32;
                return Num::toWord(strlen($bin)) . $bin . str_repeat("\0", $pad);
            case 'array':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException(esc_html('array value expected for ' . self::canonical($node)));
                }
                $items = array_values($value);
                if ($node['length'] !== null && count($items) !== $node['length']) {
                    throw new \InvalidArgumentException(esc_html(self::canonical($node) . ' needs ' . $node['length'] . ' items'));
                }
                $body = self::encodeNodes(array_fill(0, count($items), $node['of']), $items);
                return $node['length'] === null ? Num::toWord(count($items)) . $body : $body;
            case 'tuple':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException(esc_html('tuple value expected for ' . self::canonical($node)));
                }
                $items = array_values($value);
                if (count($items) !== count($node['components'])) {
                    throw new \InvalidArgumentException(esc_html(self::canonical($node) . ' needs ' . count($node['components']) . ' fields'));
                }
                return self::encodeNodes($node['components'], $items);
        }
        throw new \InvalidArgumentException(esc_html('cannot encode ' . $node['kind']));
    }

    public static function decode(array $types, $data)
    {
        $nodes = array_map([self::class, 'parseType'], $types);
        $bin = Hex::toBin($data);
        return self::decodeNodes($nodes, $bin, 0);
    }

    private static function word($bin, $pos)
    {
        if ($pos < 0 || $pos + 32 > strlen($bin)) {
            throw new \InvalidArgumentException('ABI data is too short');
        }
        return substr($bin, $pos, 32);
    }

    private static function offset($bin, $pos)
    {
        $n = Num::fromBytes(self::word($bin, $pos));
        if (Big::cmp($n, strlen($bin)) > 0) {
            throw new \InvalidArgumentException('ABI offset out of range');
        }
        return Big::intval($n);
    }

    private static function decodeNodes(array $nodes, $bin, $start)
    {
        $out = [];
        $cursor = $start;
        foreach ($nodes as $node) {
            if (self::isDynamic($node)) {
                $out[] = self::decodeValue($node, $bin, $start + self::offset($bin, $cursor));
                $cursor += 32;
            } else {
                $out[] = self::decodeValue($node, $bin, $cursor);
                $cursor += self::headSize($node);
            }
        }
        return $out;
    }

    private static function decodeValue($node, $bin, $pos)
    {
        switch ($node['kind']) {
            case 'uint':
                return Big::strval(Num::fromBytes(self::word($bin, $pos)), 10);
            case 'int':
                return Big::strval(Num::fromBytes(self::word($bin, $pos), true), 10);
            case 'bool':
                return ord(self::word($bin, $pos)[31]) !== 0;
            case 'address':
                return Address::checksum('0x' . bin2hex(substr(self::word($bin, $pos), 12)));
            case 'fixedbytes':
                return Hex::fromBin(substr(self::word($bin, $pos), 0, $node['size']));
            case 'bytes':
            case 'string':
                $len = self::offset($bin, $pos);
                if ($pos + 32 + $len > strlen($bin)) {
                    throw new \InvalidArgumentException('ABI data is too short');
                }
                $raw = substr($bin, $pos + 32, $len);
                return $node['kind'] === 'bytes' ? Hex::fromBin($raw) : $raw;
            case 'array':
                if ($node['length'] === null) {
                    $count = self::offset($bin, $pos);
                    return self::decodeNodes(array_fill(0, $count, $node['of']), $bin, $pos + 32);
                }
                return self::decodeNodes(array_fill(0, $node['length'], $node['of']), $bin, $pos);
            case 'tuple':
                return self::decodeNodes($node['components'], $bin, $pos);
        }
        throw new \InvalidArgumentException(esc_html('cannot decode ' . $node['kind']));
    }
}
