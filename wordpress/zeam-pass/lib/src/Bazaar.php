<?php

namespace ZeamPass;

use ZeamPass\Settlement\Json;

final class Bazaar
{
    const DRAFT = 'https://json-schema.org/draft/2020-12/schema';
    const EXTENSION_LIMIT = 4096;
    const HEADER_LIMIT = 12288;
    const DEPTH = 8;

    private static function isList($v)
    {
        return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));
    }

    public static function isObject($v)
    {
        return is_array($v) && $v !== [] && !self::isList($v);
    }

    private static function isNumber($v)
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v);
    }

    private static function count($v)
    {
        if (is_int($v)) {
            return $v > 0 ? $v : 0;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && $v > 0) {
            return (int) $v;
        }
        return 0;
    }

    public static function anyResult()
    {
        return ['description' => 'the tool result as JSON'];
    }

    public static function example($schema, $depth = 0)
    {
        if (!self::isObject($schema) || $depth > self::DEPTH) {
            return null;
        }
        if (isset($schema['examples']) && self::isList($schema['examples']) && $schema['examples'] !== []) {
            return $schema['examples'][0];
        }
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }
        if (isset($schema['enum']) && self::isList($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }
        $type = $schema['type'] ?? null;
        if (self::isList($type)) {
            $first = 'null';
            foreach ($type as $t) {
                if ($t !== 'null') {
                    $first = $t;
                    break;
                }
            }
            $type = $first;
        }
        if ($type === null && isset($schema['properties']) && self::isObject($schema['properties'])) {
            $type = 'object';
        }
        switch ($type) {
            case 'object':
                $props = isset($schema['properties']) && self::isObject($schema['properties']) ? $schema['properties'] : [];
                $out = new \stdClass();
                foreach (isset($schema['required']) && self::isList($schema['required']) ? $schema['required'] : [] as $k) {
                    if (is_string($k)) {
                        $out->{$k} = self::example($props[$k] ?? null, $depth + 1);
                    }
                }
                return $out;
            case 'array':
                $n = min(self::count($schema['minItems'] ?? null), self::DEPTH);
                $items = [];
                for ($i = 0; $i < $n; $i++) {
                    $items[] = self::example($schema['items'] ?? null, $depth + 1);
                }
                return $items;
            case 'string':
                return str_repeat('x', min(self::count($schema['minLength'] ?? null), 64));
            case 'integer':
                if (self::isNumber($schema['minimum'] ?? null)) {
                    return (int) ceil($schema['minimum']);
                }
                if (self::isNumber($schema['exclusiveMinimum'] ?? null)) {
                    return (int) floor($schema['exclusiveMinimum']) + 1;
                }
                return 0;
            case 'number':
                if (self::isNumber($schema['minimum'] ?? null)) {
                    return $schema['minimum'];
                }
                if (self::isNumber($schema['exclusiveMinimum'] ?? null)) {
                    return $schema['exclusiveMinimum'] + 1;
                }
                return 0;
            case 'boolean':
                return false;
            default:
                return null;
        }
    }

    private static function output($outputSchema)
    {
        $shape = self::isObject($outputSchema) ? array_merge(['type' => 'object'], $outputSchema) : self::anyResult();
        return ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'example' => $shape], 'required' => ['type']];
    }

    public static function extension($tool, $via = 'http')
    {
        if (!self::isObject($tool) || !self::isObject($tool['inputSchema'] ?? null)) {
            return null;
        }
        $schema = $tool['inputSchema'];
        if ($via === 'mcp') {
            $info = ['type' => 'mcp', 'toolName' => (string) ($tool['name'] ?? ''), 'transport' => 'streamable-http', 'inputSchema' => $schema];
            $shape = [
                'type' => 'object',
                'properties' => ['type' => ['type' => 'string', 'const' => 'mcp'], 'toolName' => ['type' => 'string'], 'transport' => ['type' => 'string', 'enum' => ['streamable-http']], 'inputSchema' => ['type' => 'object']],
                'required' => ['type', 'toolName', 'inputSchema'],
                'additionalProperties' => false,
            ];
        } else {
            $info = ['type' => 'http', 'method' => 'POST', 'bodyType' => 'json', 'body' => self::example($schema)];
            $shape = [
                'type' => 'object',
                'properties' => ['type' => ['type' => 'string', 'const' => 'http'], 'method' => ['type' => 'string', 'enum' => ['POST']], 'bodyType' => ['type' => 'string', 'enum' => ['json', 'form-data', 'text']], 'body' => $schema],
                'required' => ['type', 'method', 'bodyType', 'body'],
                'additionalProperties' => false,
            ];
        }
        return [
            'bazaar' => [
                'info' => ['input' => $info, 'output' => ['type' => 'json']],
                'schema' => ['$schema' => self::DRAFT, 'type' => 'object', 'properties' => ['input' => $shape, 'output' => self::output($tool['outputSchema'] ?? null)], 'required' => ['input']],
            ],
        ];
    }

    public static function forHeader(array $doc)
    {
        $ext = $doc['extensions'] ?? null;
        if (!is_array($ext) || !array_key_exists('bazaar', $ext)) {
            return $doc;
        }
        if (strlen(Json::encode($ext['bazaar'])) <= self::EXTENSION_LIMIT && strlen(Json::base64($doc)) <= self::HEADER_LIMIT) {
            return $doc;
        }
        unset($doc['extensions']['bazaar']);
        if ($doc['extensions'] === []) {
            unset($doc['extensions']);
        }
        return $doc;
    }
}
