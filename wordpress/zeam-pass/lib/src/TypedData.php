<?php

namespace ZeamPass;

final class TypedData
{
    const DOMAIN_FIELDS = [
        'name' => 'string',
        'version' => 'string',
        'chainId' => 'uint256',
        'verifyingContract' => 'address',
        'salt' => 'bytes32',
    ];

    public static function domainTypes(array $domain)
    {
        $fields = [];
        foreach (self::DOMAIN_FIELDS as $name => $type) {
            if (array_key_exists($name, $domain) && $domain[$name] !== null) {
                $fields[] = ['name' => $name, 'type' => $type];
            }
        }
        return $fields;
    }

    public static function hash(array $domain, array $types, $primaryType, array $message)
    {
        if (!isset($types['EIP712Domain'])) {
            $types['EIP712Domain'] = self::domainTypes($domain);
        }
        $out = "\x19\x01" . self::hashStructBin('EIP712Domain', $domain, $types);
        if ($primaryType !== 'EIP712Domain') {
            $out .= self::hashStructBin($primaryType, $message, $types);
        }
        return Keccak::hex($out);
    }

    public static function encodeType($primaryType, array $types)
    {
        $deps = self::dependencies($primaryType, $types, []);
        $deps = array_values(array_filter($deps, function ($d) use ($primaryType) {
            return $d !== $primaryType;
        }));
        sort($deps, SORT_STRING);
        $out = '';
        foreach (array_merge([$primaryType], $deps) as $t) {
            $fields = array_map(function ($f) {
                return $f['type'] . ' ' . $f['name'];
            }, $types[$t]);
            $out .= $t . '(' . implode(',', $fields) . ')';
        }
        return $out;
    }

    private static function baseType($type)
    {
        return preg_match('/^([^\[]*)/', $type, $m) ? $m[1] : $type;
    }

    private static function dependencies($primaryType, array $types, array $found)
    {
        $base = self::baseType($primaryType);
        if (in_array($base, $found, true) || !isset($types[$base])) {
            return $found;
        }
        $found[] = $base;
        foreach ($types[$base] as $field) {
            $found = self::dependencies($field['type'], $types, $found);
        }
        return $found;
    }

    private static function hashStructBin($primaryType, array $data, array $types)
    {
        if (!isset($types[$primaryType])) {
            throw new \InvalidArgumentException(esc_html("unknown EIP-712 type: $primaryType"));
        }
        $enc = Keccak::hash(self::encodeType($primaryType, $types));
        foreach ($types[$primaryType] as $field) {
            if (!array_key_exists($field['name'], $data)) {
                throw new \InvalidArgumentException(esc_html("$primaryType is missing field " . $field['name']));
            }
            $enc .= self::encodeField($types, $field['type'], $data[$field['name']]);
        }
        return Keccak::hash($enc);
    }

    private static function encodeField(array $types, $type, $value)
    {
        if (isset($types[$type])) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException(esc_html("struct value expected for $type"));
            }
            return self::hashStructBin($type, $value, $types);
        }
        if ($type === 'bytes') {
            return Keccak::hash(Hex::toBin($value));
        }
        if ($type === 'string') {
            return Keccak::hash((string) $value);
        }
        if (substr($type, -1) === ']') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException(esc_html("array value expected for $type"));
            }
            $inner = substr($type, 0, strrpos($type, '['));
            if (preg_match('/\[(\d+)\]$/', $type, $m) && count($value) !== (int) $m[1]) {
                throw new \InvalidArgumentException(esc_html("$type needs " . $m[1] . ' items'));
            }
            $enc = '';
            foreach (array_values($value) as $item) {
                $enc .= self::encodeField($types, $inner, $item);
            }
            return Keccak::hash($enc);
        }
        $node = Abi::parseType($type);
        if (Abi::isDynamic($node) || $node['kind'] === 'tuple' || $node['kind'] === 'array') {
            throw new \InvalidArgumentException(esc_html("unsupported EIP-712 field type: $type"));
        }
        return Abi::encodeValue($node, $value);
    }
}
