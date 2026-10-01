<?php

namespace ZeamPass;

final class Transport
{
    private static $handler = null;

    public static function use(?callable $handler)
    {
        self::$handler = $handler;
    }

    public static function request($method, $url, array $headers, $body, $timeout, $connectTimeout = 5)
    {
        if (!preg_match('#^https?://#i', (string) $url)) {
            throw new \InvalidArgumentException('a URL starts with http:// or https://');
        }
        $method = strtoupper((string) $method);
        if (self::$handler !== null) {
            return call_user_func(self::$handler, $method, $url, $headers, $body, (float) $timeout, (float) $connectTimeout);
        }
        if (function_exists('wp_remote_request')) {
            return self::wordpress($method, $url, $headers, $body, (float) $timeout);
        }
        throw new \RuntimeException('no HTTP transport: run inside WordPress or install one with Transport::use()');
    }

    private static function wordpress($method, $url, array $headers, $body, $timeout)
    {
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => $timeout, 'redirection' => 0];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) {
            throw new \RuntimeException(esc_html('request failed: ' . $r->get_error_message()));
        }
        $out = [];
        foreach (wp_remote_retrieve_headers($r) as $k => $v) {
            $out[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        return ['status' => (int) wp_remote_retrieve_response_code($r), 'headers' => $out, 'body' => (string) wp_remote_retrieve_body($r)];
    }
}
