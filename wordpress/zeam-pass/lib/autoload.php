<?php

namespace ZeamPass {

    if (!defined('ABSPATH')) {
        exit;
    }

    if (!\function_exists('esc_html')) {
        function esc_html($text)
        {
            return (string) $text;
        }
    }

    if (!\function_exists('ZeamPass\\message')) {
        function message(\Throwable $e)
        {
            return html_entity_decode($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    if (!function_exists('ZeamPass\\zeam_pass_core_autoload')) {
        function zeam_pass_core_autoload($class)
        {
            static $roots = null;
            if ($roots === null) {
                $base = __DIR__;
                $roots = [
                    'ZeamPass\\' => $base . '/src/',
                    'Elliptic\\' => $base . '/vendor/simplito/elliptic-php/lib/',
                    'BN\\' => $base . '/vendor/simplito/bn-php/lib/',
                    'BI\\' => $base . '/vendor/simplito/bigint-wrapper-php/lib/',
                    'kornrunner\\' => $base . '/vendor/kornrunner/keccak/src/',
                ];
            }
            foreach ($roots as $prefix => $dir) {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    continue;
                }
                if ($prefix !== 'ZeamPass\\') {
                    Core::assertReady();
                }
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }
        }

        spl_autoload_register('ZeamPass\\zeam_pass_core_autoload');
    }
}

namespace ZeamPass\Settlement {

    if (!\function_exists('esc_html')) {
        function esc_html($text)
        {
            return (string) $text;
        }
    }
}

namespace ZeamPass\Gate {

    if (!\function_exists('esc_html')) {
        function esc_html($text)
        {
            return (string) $text;
        }
    }
}
