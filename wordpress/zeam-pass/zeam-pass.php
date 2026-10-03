<?php
/**
 * Plugin Name: ZEAM Pass
 * Plugin URI: https://zeampass.com
 * Description: Put a gate, a paywall or both in front of your posts for AI agents, on your own site. Agents search and read over MCP and over x402 HTTP. No sign-up and no account: install, connect the wallet you are paid to, choose. Your earnings go to your own split, 90.01% to your wallet.
 * Version: 1.0.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ZEAM Labs, LLC
 * Author URI: https://www.zeamlabs.com
 * License: GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: zeam-pass
 * Update URI: https://zeampass.com/downloads/zeam-pass.json
 */

if (!defined('ABSPATH')) {
    exit;
}

const ZEAM_PASS_NS = 'zeam-pass';
const ZEAM_PASS_META_PAYMENT = 'x402/payment';
const ZEAM_PASS_META_RESPONSE = 'x402/payment-response';
const ZEAM_PASS_META_GRANTED_BY = 'zeam-pass/granted-by';
const ZEAM_PASS_META_PRICE = 'zeam-pass/price';
const ZEAM_PASS_META_LINE = 'zeam-pass/line';
const ZEAM_PASS_META_METER = 'zeam-pass/meter';
const ZEAM_PASS_LINE_OPS = ['open', 'prove', 'on', 'off', 'status', 'close'];
const ZEAM_PASS_TIME_TOOLS = ['buy_time', 'line'];
const ZEAM_PASS_BUILTIN_TOOLS = ['search_posts', 'read_post'];
const ZEAM_PASS_NO_UNITS = 'the tool reported no units. Nothing was charged.';
const ZEAM_PASS_VERSION = '1.0.6';
const ZEAM_PASS_RESULT_NOT_JSON = 'the result is not valid JSON (a non-finite number or a non-JSON value); nothing was charged';

require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/keys.php';
require_once __DIR__ . '/includes/engine.php';
require_once __DIR__ . '/includes/update.php';

function zeam_pass_opt($key, $default = null)
{
    $all = get_option('zeam_pass', []);
    return is_array($all) && array_key_exists($key, $all) ? $all[$key] : $default;
}

function zeam_pass_set(array $values)
{
    $all = get_option('zeam_pass', []);
    update_option('zeam_pass', array_merge(is_array($all) ? $all : [], $values), false);
}

function zeam_pass_site_name()
{
    return html_entity_decode(wp_strip_all_tags(get_bloginfo('name')), ENT_QUOTES);
}

final class ZeamPassMeter
{
    private $units = null;
    private $deadline;
    private $channelId;

    public function __construct($deadlineMs = null, $channelId = null)
    {
        $this->deadline = $deadlineMs === null ? null : (int) $deadlineMs;
        $this->channelId = $channelId === null ? null : strtolower((string) $channelId);
    }

    public function deadlineMs()
    {
        return $this->deadline;
    }

    public function channelId()
    {
        return $this->channelId;
    }

    public function units($n)
    {
        $this->units = \ZeamPass\Pricing::units($n);
    }

    public function reported()
    {
        return $this->units;
    }
}

function zeam_pass_builtin_tools()
{
    $site = zeam_pass_site_name();
    return [
        'search_posts' => [
            'description' => "Search the posts on {$site}: titles, links, dates and excerpts.",
            'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string', 'minLength' => 1, 'pattern' => '\\S', 'description' => 'what to search for'], 'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'page of 10 results']], 'required' => ['q']],
        ],
        'read_post' => [
            'description' => "Read one post on {$site} in full, as plain text.",
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'the post id, from search_posts']], 'required' => ['id']],
        ],
    ];
}

function zeam_pass_free_list()
{
    $out = [];
    foreach ((array) zeam_pass_opt('free', []) as $t) {
        if (is_string($t) && in_array($t, ZEAM_PASS_BUILTIN_TOOLS, true)) {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}

function zeam_pass_free_limit()
{
    $n = zeam_pass_opt('free_limit', '');
    return (is_int($n) || (is_string($n) && ctype_digit($n))) && (int) $n >= 1 ? (int) $n : null;
}

function zeam_pass_has_price($value)
{
    return $value !== null && $value !== '';
}

function zeam_pass_own_price(array $t)
{
    return zeam_pass_has_price($t['price'] ?? null) || zeam_pass_has_price($t['unit'] ?? null);
}

function zeam_pass_site_fallback()
{
    $micro = zeam_pass_price_micro();
    return $micro >= 1 ? $micro : null;
}

function zeam_pass_time()
{
    if (zeam_pass_mode() === 'gate') {
        return null;
    }
    $block = trim((string) zeam_pass_opt('time_block', ''));
    if ($block === '') {
        return null;
    }
    $ms = zeam_pass_opt('time_block_ms', '');
    $blockMs = (is_int($ms) || (is_string($ms) && ctype_digit($ms))) && (int) $ms >= 1 ? (int) $ms : \ZeamPass\Meter\TimeMeter::DEFAULT_BLOCK_MS;
    try {
        $micro = \ZeamPass\Pricing::microOf($block, 'time.block');
        return \ZeamPass\Meter\TimeMeter::options(['block' => $block, 'blockMs' => $blockMs, 'maxBlocks' => min(\ZeamPass\Meter\TimeMeter::DEFAULT_MAX_BLOCKS, intdiv(1000000000, $micro))]);
    } catch (InvalidArgumentException $e) {
        return null;
    }
}

function zeam_pass_time_meter()
{
    $time = zeam_pass_time();
    return $time === null ? null : new \ZeamPass\Meter\TimeMeter($time, new ZeamPassMeterStore('clock'), new ZeamPassMeterStore('line'), 'zeam_pass_now_ms');
}

function zeam_pass_blocks(array $args)
{
    $b = $args['blocks'] ?? null;
    if (is_float($b) && is_finite($b) && floor($b) === $b && abs($b) <= 9007199254740991) {
        $b = (int) $b;
    }
    return is_int($b) && $b >= 1 ? $b : 1;
}

function zeam_pass_time_tools(array $time)
{
    $usd = \ZeamPass\Pricing::usd($time['blockMicro']);
    return [
        'buy_time' => [
            'builtin' => 'buy_time',
            'description' => "Buys line time: \${$usd} per {$time['blockMs']} ms block, for the channel that pays. Then open a line.",
            'inputSchema' => ['type' => 'object', 'properties' => ['blocks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $time['maxBlocks'], 'description' => "blocks of {$time['blockMs']} ms; default 1"]]],
            'free' => false,
        ],
        'line' => [
            'builtin' => 'line',
            'description' => 'A line spends bought time without a payment per call. op: open {channelId} returns a message to sign with the payer key; prove {channelId, nonce, signature} returns the credential; on, off, status, close {credential}.',
            'inputSchema' => ['type' => 'object', 'properties' => ['op' => ['enum' => ZEAM_PASS_LINE_OPS], 'channelId' => ['type' => 'string'], 'nonce' => ['type' => 'string'], 'signature' => ['type' => 'string'], 'credential' => ['type' => 'string']], 'required' => ['op']],
            'free' => true,
        ],
    ];
}

function zeam_pass_tools()
{
    $tools = zeam_pass_builtin_tools();
    $time = zeam_pass_time();
    $prices = zeam_pass_opt('tool_prices', []);
    $free = zeam_pass_free_list();
    foreach ($tools as $name => $t) {
        if (is_array($prices) && isset($prices[$name]) && is_string($prices[$name]) && $prices[$name] !== '') {
            $tools[$name]['price'] = $prices[$name];
        }
        if (in_array($name, $free, true)) {
            $tools[$name]['free'] = true;
        }
    }
    $added = apply_filters('zeam_pass_tools', $tools);
    $out = [];
    foreach (is_array($added) ? $added : $tools as $name => $t) {
        if (!is_string($name) || !preg_match('/^[a-z0-9_]{1,64}$/D', $name) || !is_array($t) || in_array($name, ZEAM_PASS_TIME_TOOLS, true)) {
            continue;
        }
        unset($t['builtin']);
        if (!in_array($name, ZEAM_PASS_BUILTIN_TOOLS, true) && !(isset($t['run']) && is_callable($t['run']))) {
            continue;
        }
        $t['description'] = isset($t['description']) && is_string($t['description']) ? $t['description'] : '';
        $t['inputSchema'] = isset($t['inputSchema']) && zeam_pass_is_object($t['inputSchema']) ? $t['inputSchema'] : ['type' => 'object'];
        $t['free'] = !empty($t['free']);
        $metered = $t['meter'] ?? null;
        if ($metered !== null && ($metered !== 'time' || $time === null || $t['free'])) {
            continue;
        }
        if (!$t['free'] && zeam_pass_own_price($t)) {
            try {
                \ZeamPass\Pricing::priceOf(['price' => $t['price'] ?? null, 'unit' => $t['unit'] ?? null], zeam_pass_site_fallback());
            } catch (\InvalidArgumentException $e) {
                if (\ZeamPass\message($e) !== \ZeamPass\Pricing::NO_PRICE) {
                    continue;
                }
            }
        }
        $out[$name] = $t;
    }
    return $time === null ? $out : $out + zeam_pass_time_tools($time);
}

function zeam_pass_price_for($name, array $t, array $args = [])
{
    if (($t['builtin'] ?? null) === 'buy_time') {
        return ['micro' => zeam_pass_blocks($args) * zeam_pass_time()['blockMicro'], 'unitMicro' => null];
    }
    if (zeam_pass_own_price($t)) {
        return \ZeamPass\Pricing::priceOf(['price' => $t['price'] ?? null, 'unit' => $t['unit'] ?? null], zeam_pass_site_fallback());
    }
    if (has_filter('zeam_pass_prices')) {
        return \ZeamPass\Pricing::priceOf(apply_filters('zeam_pass_prices', null, $name, $args), zeam_pass_site_fallback());
    }
    return \ZeamPass\Pricing::priceOf(null, zeam_pass_site_fallback());
}

function zeam_pass_price_tag($name, array $t)
{
    $time = zeam_pass_time();
    if (($t['builtin'] ?? null) === 'line') {
        return ['usd' => '0', 'per' => 'call', 'free' => true];
    }
    if (($t['builtin'] ?? null) === 'buy_time') {
        return ['usd' => \ZeamPass\Pricing::usd($time['blockMicro']), 'per' => 'block', 'blockMs' => $time['blockMs'], 'maxBlocks' => $time['maxBlocks']];
    }
    if (($t['meter'] ?? null) === 'time' && $time !== null && !(has_filter('zeam_pass_prices') && !zeam_pass_own_price($t))) {
        try {
            $p = zeam_pass_price_for($name, $t);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return ['per' => 'time', 'blockUSD' => \ZeamPass\Pricing::usd($time['blockMicro']), 'blockMs' => $time['blockMs'], 'callUSD' => \ZeamPass\Pricing::usd($p['micro']), 'callMs' => intdiv($p['micro'] * $time['blockMs'], $time['blockMicro'])];
    }
    if (!empty($t['free'])) {
        return \ZeamPass\Pricing::describe(null, ['free' => true, 'perHour' => zeam_pass_free_limit()]);
    }
    if (zeam_pass_mode() === 'gate') {
        return ['usd' => '0', 'per' => 'call'];
    }
    if (!zeam_pass_own_price($t) && has_filter('zeam_pass_prices')) {
        return \ZeamPass\Pricing::describe(null, ['varies' => true]);
    }
    try {
        return \ZeamPass\Pricing::describe(zeam_pass_price_for($name, $t));
    } catch (\InvalidArgumentException $e) {
        return null;
    }
}

function zeam_pass_listing()
{
    if (zeam_pass_mode() === 'gate') {
        return [];
    }
    $prices = [];
    foreach (zeam_pass_tools() as $name => $t) {
        $tag = zeam_pass_price_tag($name, $t);
        if ($tag !== null) {
            $prices[$name] = $tag;
        }
    }
    $time = zeam_pass_time();
    return $time === null ? ['prices' => $prices] : ['prices' => $prices, 'time' => \ZeamPass\Meter\TimeMeter::text($time, untrailingslashit(rest_url(ZEAM_PASS_NS)))];
}

function zeam_pass_client_address()
{
    return isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

function zeam_pass_post_view(WP_Post $post, $full)
{
    $view = [
        'id' => $post->ID,
        'title' => html_entity_decode(get_the_title($post), ENT_QUOTES),
        'url' => get_permalink($post),
        'date' => get_post_time('c', true, $post),
        'excerpt' => wp_strip_all_tags(get_the_excerpt($post)),
    ];
    if ($full) {
        $view['author'] = get_the_author_meta('display_name', $post->post_author);
        $view['text'] = trim(wp_strip_all_tags(apply_filters('the_content', $post->post_content))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
    }
    return $view;
}

function zeam_pass_readable($id)
{
    $post = get_post((int) $id);
    return $post instanceof WP_Post && $post->post_status === 'publish' && $post->post_type === 'post' && !post_password_required($post) ? $post : null;
}

function zeam_pass_is_object($v)
{
    return is_array($v) && ($v === [] || array_keys($v) !== range(0, count($v) - 1));
}

function zeam_pass_type_ok($type, $v)
{
    switch ($type) {
        case 'string':
            return is_string($v);
        case 'integer':
            return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
        case 'number':
            return is_int($v) || is_float($v);
        case 'boolean':
            return is_bool($v);
        case 'object':
            return zeam_pass_is_object($v);
        case 'array':
            return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));
        default:
            return true;
    }
}

function zeam_pass_validate($tool, $args)
{
    $schema = zeam_pass_tools()[$tool]['inputSchema'];
    $bad = function ($message) { return ['error' => 'invalid_arguments', 'message' => $message, 'status' => 400]; };
    if (!zeam_pass_is_object($args)) {
        return $bad('the arguments must be an object');
    }
    foreach ($schema['required'] ?? [] as $key) {
        if (!array_key_exists($key, $args)) {
            return $bad("missing required argument: {$key}");
        }
    }
    foreach ($schema['properties'] ?? [] as $key => $prop) {
        if (!array_key_exists($key, $args)) {
            continue;
        }
        $v = $args[$key];
        if (isset($prop['type']) && !zeam_pass_type_ok($prop['type'], $v)) {
            return $bad("argument {$key} must be {$prop['type']}");
        }
        if (isset($prop['minimum']) && (is_int($v) || is_float($v)) && $v < $prop['minimum']) {
            return $bad("argument {$key} must be at least {$prop['minimum']}");
        }
        if (isset($prop['maximum']) && (is_int($v) || is_float($v)) && $v > $prop['maximum']) {
            return $bad("argument {$key} must be at most {$prop['maximum']}");
        }
        $length = is_string($v) ? (function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v)) : null;
        if (isset($prop['minLength']) && $length !== null && $length < $prop['minLength']) {
            return $bad("argument {$key} must be at least {$prop['minLength']} character" . ($prop['minLength'] === 1 ? '' : 's'));
        }
        if (isset($prop['maxLength']) && $length !== null && $length > $prop['maxLength']) {
            return $bad("argument {$key} must be at most {$prop['maxLength']} characters");
        }
        if (isset($prop['pattern']) && is_string($v) && preg_match('/' . str_replace('/', '\\/', $prop['pattern']) . '/u', $v) !== 1) {
            return $bad("argument {$key} must match the pattern {$prop['pattern']}");
        }
    }
    return null;
}

function zeam_pass_precheck($tool, array $args)
{
    if ($tool === 'read_post' && !zeam_pass_readable($args['id'] ?? 0)) {
        return ['error' => 'not_found', 'message' => 'no published post with that id', 'status' => 404];
    }
    return null;
}

function zeam_pass_run($tool, array $args, ?ZeamPassMeter $meter = null)
{
    $tools = zeam_pass_tools();
    if (($tools[$tool]['builtin'] ?? null) === 'buy_time') {
        $time = zeam_pass_time();
        $blocks = zeam_pass_blocks($args);
        $channelId = $meter ? $meter->channelId() : null;
        $st = zeam_pass_time_meter()->status($channelId);
        return ['channelId' => $channelId, 'boughtMs' => $blocks * $time['blockMs'], 'msRemaining' => $st['msRemaining'] + $blocks * $time['blockMs'], 'blockMs' => $time['blockMs'], 'paidUSD' => \ZeamPass\Pricing::usd($blocks * $time['blockMicro'])];
    }
    if (isset($tools[$tool]['run']) && is_callable($tools[$tool]['run'])) {
        return call_user_func($tools[$tool]['run'], $args, $meter ?: new ZeamPassMeter());
    }
    if ($tool === 'search_posts') {
        $q = sanitize_text_field((string) $args['q']);
        $page = max(1, (int) ($args['page'] ?? 1));
        $found = new WP_Query(['s' => $q, 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 10, 'paged' => $page, 'has_password' => false]);
        $posts = [];
        foreach ($found->posts as $p) {
            if ($p instanceof WP_Post) {
                $posts[] = zeam_pass_post_view($p, false);
            }
        }
        return ['query' => $q, 'page' => $page, 'total' => (int) $found->found_posts, 'posts' => $posts];
    }
    if ($tool === 'read_post') {
        $post = zeam_pass_readable($args['id'] ?? 0);
        if (!$post) {
            return new WP_Error('not_found', 'no published post with that id', ['status' => 404]);
        }
        return zeam_pass_post_view($post, true);
    }
    return new WP_Error('unknown_tool', 'no such tool', ['status' => 404]);
}

function zeam_pass_run_safely($tool, array $args, ?ZeamPassMeter $meter = null)
{
    try {
        $out = zeam_pass_run($tool, $args, $meter);
    } catch (Throwable $e) {
        return new WP_Error('tool_failed', 'the tool failed', ['status' => 500]);
    }
    if (is_array($out) && json_encode($out) === false) {
        return new WP_Error('tool_failed', ZEAM_PASS_RESULT_NOT_JSON, ['status' => 500]);
    }
    return is_array($out) ? $out : (is_wp_error($out) ? $out : new WP_Error('tool_failed', 'the tool failed', ['status' => 500]));
}

function zeam_pass_refusal($status, array $body, array $headers = [])
{
    return ['delivered' => false, 'status' => $status, 'headers' => $headers, 'body' => $body];
}

function zeam_pass_first_header(array $headers, array $names)
{
    foreach ($names as $n) {
        if (isset($headers[$n]) && is_string($headers[$n]) && trim($headers[$n]) !== '') {
            return trim($headers[$n]);
        }
    }
    return '';
}

function zeam_pass_probe(array $t, $args, $payment, $line)
{
    return $payment === '' && $args === [] && !isset($t['builtin']) && empty($t['free']) && !(($t['meter'] ?? null) === 'time' && $line !== '');
}

function zeam_pass_serve($tool, $path, $resource, $args, array $payment_headers, $who = '')
{
    $t = zeam_pass_tools()[$tool];
    $line = zeam_pass_first_header($payment_headers, ['x-line']);
    $payment = zeam_pass_first_header($payment_headers, ['payment-signature', 'x-payment']);
    $bad = zeam_pass_validate($tool, $args);
    if (!$bad) {
        $bad = zeam_pass_precheck($tool, $args);
    }
    $probe = $bad && zeam_pass_probe($t, $args, $payment, $line);
    if ($bad && !$probe) {
        return zeam_pass_refusal($bad['status'], $bad) + ['arguments' => true];
    }
    if (!zeam_pass_ready()) {
        return zeam_pass_refusal(503, ['error' => 'not_ready', 'message' => 'this site has not finished setting up ZEAM Pass']);
    }
    if (($t['builtin'] ?? null) === 'line') {
        return zeam_pass_serve_line_op($args, $line, strpos((string) $path, 'mcp:') === 0);
    }
    if (!empty($t['free'])) {
        return zeam_pass_serve_free($tool, $args, (string) $who);
    }
    $grant = zeam_pass_first_header($payment_headers, ['x-grant']);
    $res = zeam_pass_resource($tool, $resource);
    $timed = ($t['meter'] ?? null) === 'time';
    try {
        if ($timed && $line !== '') {
            return zeam_pass_serve_on_line($tool, $args, $line);
        }
        if (zeam_pass_mode() === 'gate') {
            return zeam_pass_serve_gate($tool, $res, $args, $payment, $grant);
        }
        try {
            $price = zeam_pass_price_for($tool, $t, $args);
        } catch (Throwable $e) {
            return $probe ? zeam_pass_refusal($bad['status'], $bad) + ['arguments' => true] : zeam_pass_refusal(500, ['error' => 'price_invalid', 'tool' => $tool, 'message' => \ZeamPass\message($e)]);
        }
        return zeam_pass_serve_paid($tool, $res, $args, $payment, $grant, zeam_pass_mode() === 'both', $price, $timed ? zeam_pass_time_meter()->callMs($price['micro']) : null, \ZeamPass\Bazaar::extension(['name' => $tool] + $t, strpos((string) $path, 'mcp:') === 0 ? 'mcp' : 'http'));
    } catch (Throwable $e) {
        return zeam_pass_refusal(503, ['error' => 'payment_unavailable', 'message' => 'the payment check failed; nothing was charged. Retry.']);
    }
}

function zeam_pass_decode_header($value)
{
    $decoded = json_decode((string) base64_decode((string) $value, true), true);
    return is_array($decoded) ? $decoded : null;
}

function zeam_pass_http(WP_REST_Request $request)
{
    $tool = (string) $request['tool'];
    $tools = zeam_pass_tools();
    if (!isset($tools[$tool])) {
        return new WP_REST_Response(['error' => 'unknown_tool', 'tool' => $tool, 'tools' => array_keys($tools)], 404);
    }
    $raw = trim((string) $request->get_body());
    $args = $raw === '' ? [] : json_decode($raw, true);
    if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response(['error' => 'invalid_arguments', 'tool' => $tool, 'message' => 'the body is not JSON'], 400);
    }
    $s = zeam_pass_serve($tool, '/v1/' . $tool, rest_url(ZEAM_PASS_NS . '/v1/' . $tool), $args, [
        'payment-signature' => $request->get_header('payment_signature'),
        'x-payment' => $request->get_header('x_payment'),
        'x-grant' => $request->get_header('x_grant'),
        'x-line' => $request->get_header('x_line'),
    ], zeam_pass_client_address());
    $response = new WP_REST_Response($s['body'], $s['status']);
    foreach ($s['headers'] as $k => $v) {
        $response->header($k, $v);
    }
    return $response;
}

function zeam_pass_mcp_error(array $body)
{
    $out = ['isError' => true, 'content' => [['type' => 'text', 'text' => wp_json_encode($body)]]];
    if (zeam_pass_is_object($body) && $body !== []) {
        $out['structuredContent'] = $body;
    }
    return $out;
}

function zeam_pass_mcp_call(array $params)
{
    $tool = (string) $params['name'];
    $tools = zeam_pass_tools();
    if (!isset($tools[$tool])) {
        return zeam_pass_mcp_error(['error' => 'unknown_tool', 'tool' => $tool, 'tools' => array_keys($tools)]);
    }
    $args = $params['arguments'] ?? [];
    $payment = $params['_meta'][ZEAM_PASS_META_PAYMENT] ?? null;
    $line = isset($params['_meta'][ZEAM_PASS_META_LINE]) && is_string($params['_meta'][ZEAM_PASS_META_LINE]) && trim($params['_meta'][ZEAM_PASS_META_LINE]) !== '' ? $params['_meta'][ZEAM_PASS_META_LINE] : zeam_pass_request_line();
    $s = zeam_pass_serve($tool, 'mcp:' . $tool, rest_url(ZEAM_PASS_NS . '/mcp'), $args, ['payment-signature' => $payment ? base64_encode(wp_json_encode($payment)) : null, 'x-grant' => zeam_pass_request_grant(), 'x-line' => $line], zeam_pass_client_address());
    if (isset($s['mcpBody'])) {
        return ['isError' => true, 'structuredContent' => $s['mcpBody'], 'content' => [['type' => 'text', 'text' => wp_json_encode($s['mcpBody'])]]];
    }
    if (!$s['delivered']) {
        $required = zeam_pass_decode_header($s['headers']['PAYMENT-REQUIRED'] ?? '') ?: (isset($s['body']['accepts']) ? $s['body'] : null);
        if (!$required && $s['status'] === 402) {
            $required = array_merge(['x402Version' => 2, 'accepts' => []], $s['body']);
        }
        if ($required) {
            $required = array_merge(['x402Version' => 2], $s['body'], $required);
            return ['isError' => true, 'structuredContent' => $required, 'content' => [['type' => 'text', 'text' => wp_json_encode($required)]]];
        }
        if (!empty($s['arguments']) || !zeam_pass_is_object($s['body']) || $s['body'] === []) {
            return zeam_pass_mcp_error($s['body']);
        }
        return ['isError' => true, 'structuredContent' => $s['body'], 'content' => [['type' => 'text', 'text' => wp_json_encode($s['body'])]]];
    }
    $result = ['content' => [['type' => 'text', 'text' => wp_json_encode($s['body'])]], 'structuredContent' => $s['body']];
    $meta = [];
    $settled = zeam_pass_decode_header($s['headers']['PAYMENT-RESPONSE'] ?? '');
    if ($settled) {
        $meta[ZEAM_PASS_META_RESPONSE] = $settled;
    }
    if (isset($s['headers'][ZEAM_PASS_GRANTED_BY]) && is_string($s['headers'][ZEAM_PASS_GRANTED_BY])) {
        $meta[ZEAM_PASS_META_GRANTED_BY] = $s['headers'][ZEAM_PASS_GRANTED_BY];
    }
    if (isset($s['meter'])) {
        $meta[ZEAM_PASS_META_METER] = $s['meter'];
    }
    if ($meta) {
        $result['_meta'] = $meta;
    }
    return $result;
}

function zeam_pass_bad_params($msg)
{
    $params = $msg['params'] ?? null;
    if (array_key_exists('params', $msg) && !zeam_pass_is_object($params)) {
        return 'params must be an object';
    }
    if ($msg['method'] !== 'tools/call') {
        return null;
    }
    if (!is_string($params['name'] ?? null) || $params['name'] === '') {
        return 'params.name must be a string';
    }
    if (isset($params['arguments']) && !zeam_pass_is_object($params['arguments'])) {
        return 'params.arguments must be an object';
    }
    if (array_key_exists('_meta', $params) && !zeam_pass_is_object($params['_meta'])) {
        return 'params._meta must be an object';
    }
    if (isset($params['_meta'][ZEAM_PASS_META_PAYMENT]) && !zeam_pass_is_object($params['_meta'][ZEAM_PASS_META_PAYMENT])) {
        return 'params._meta["x402/payment"] must be an object';
    }
    return null;
}

function zeam_pass_mcp_one($msg)
{
    if (!zeam_pass_is_object($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !is_string($msg['method'] ?? null)) {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'invalid request']];
    }
    if (!array_key_exists('id', $msg)) {
        return null;
    }
    $id = is_string($msg['id']) || is_int($msg['id']) || is_float($msg['id']) ? $msg['id'] : null;
    $fail = function ($code, $message) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]; };
    $reply = function ($result) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; };
    $invalid = zeam_pass_bad_params($msg);
    if ($invalid) {
        return $fail(-32602, $invalid);
    }
    $params = $msg['params'] ?? [];
    try {
        switch ($msg['method']) {
            case 'initialize':
                $asked = $params['protocolVersion'] ?? '';
                $known = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
                return $reply(['protocolVersion' => in_array($asked, $known, true) ? $asked : $known[0], 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => zeam_pass_site_name(), 'version' => ZEAM_PASS_VERSION]]);
            case 'ping':
                return $reply((object) []);
            case 'tools/list':
                $list = [];
                foreach (zeam_pass_tools() as $name => $t) {
                    $entry = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['inputSchema']];
                    $tag = zeam_pass_price_tag($name, $t);
                    if ($tag !== null) {
                        $entry['_meta'] = [ZEAM_PASS_META_PRICE => $tag];
                    }
                    $list[] = $entry;
                }
                return $reply(['tools' => $list]);
            case 'tools/call':
                return $reply(zeam_pass_mcp_call($params));
            default:
                return $fail(-32601, 'method not found');
        }
    } catch (Throwable $e) {
        return $fail(-32603, 'internal error');
    }
}

function zeam_pass_request_grant($set = null)
{
    static $grant = null;
    if ($set !== null) {
        $grant = (string) $set;
    }
    return $grant;
}

function zeam_pass_request_line($set = null)
{
    static $line = '';
    if ($set !== null) {
        $line = (string) $set;
    }
    return $line;
}

function zeam_pass_mcp(WP_REST_Request $request)
{
    zeam_pass_request_grant((string) $request->get_header('x_grant'));
    zeam_pass_request_line((string) $request->get_header('x_line'));
    $body = json_decode($request->get_body(), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'parse error']], 400);
    }
    if (!is_array($body) || $body === []) {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'invalid request']], 400);
    }
    $batch = !zeam_pass_is_object($body);
    $answers = array_values(array_filter(array_map('zeam_pass_mcp_one', $batch ? $body : [$body])));
    if (!$answers) {
        return new WP_REST_Response(null, 202);
    }
    return new WP_REST_Response($batch ? $answers : $answers[0], 200);
}

function zeam_pass_mcp_get()
{
    $response = new WP_REST_Response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'method not allowed: POST JSON-RPC to this address']], 405);
    $response->header('Allow', 'POST');
    return $response;
}

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    return $result === null && $request->get_method() === 'GET' && untrailingslashit($request->get_route()) === '/' . ZEAM_PASS_NS . '/mcp' ? zeam_pass_mcp_get() : $result;
}, 10, 3);

function zeam_pass_reads_its_own_body($request)
{
    $route = untrailingslashit($request->get_route());
    return $route === '/' . ZEAM_PASS_NS . '/mcp' || $route === '/' . ZEAM_PASS_NS . '/refund' || $route === '/' . ZEAM_PASS_NS . '/line' || preg_match('#^/' . preg_quote(ZEAM_PASS_NS, '#') . '/v1/[a-z0-9_]+$#D', $route) === 1;
}

add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {
    if (is_wp_error($response) && $response->get_error_code() === 'rest_invalid_json' && zeam_pass_reads_its_own_body($request)) {
        return null;
    }
    return $response;
}, 10, 3);

function zeam_pass_refund_route(WP_REST_Request $request)
{
    $raw = (string) $request->get_body();
    $shape = json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response(['op' => 'refund_failed', 'code' => 'invalid_json', 'why' => 'the body is not JSON'], 400);
    }
    $body = json_decode($raw, true);
    if (!($shape instanceof stdClass) || !is_array($body)) {
        return new WP_REST_Response(['op' => 'refund_failed', 'code' => 'invalid_request', 'why' => 'expected { channelId, issued, signature }, optional selfSend or gasPayment'], 400);
    }
    try {
        $r = zeam_pass_refund($body);
    } catch (Throwable $e) {
        $r = ['status' => 503, 'body' => ['op' => 'refund_failed', 'code' => 'refund_unavailable', 'why' => 'the refund could not be handled right now']];
    }
    return new WP_REST_Response($r['body'], $r['status']);
}

function zeam_pass_line_route(WP_REST_Request $request)
{
    $body = json_decode((string) $request->get_body(), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_REST_Response(['op' => 'line_failed', 'code' => 'bad_request', 'why' => 'the body is not JSON'], 400);
    }
    try {
        $l = zeam_pass_line_op($body, trim((string) $request->get_header('x_line')));
    } catch (Throwable $e) {
        $l = ['status' => 503, 'body' => ['op' => 'line_failed', 'code' => 'line_unavailable', 'why' => 'the line could not be read right now. Retry.']];
    }
    return new WP_REST_Response($l['body'], $l['status']);
}

function zeam_pass_own_route($request)
{
    return !($request instanceof WP_REST_Request) || strpos($request->get_route(), '/' . ZEAM_PASS_NS . '/') === 0;
}

add_filter('rest_allowed_cors_headers', function ($headers, $request = null) {
    return zeam_pass_own_route($request) ? array_values(array_unique(array_merge($headers, ['payment-signature', 'x-payment', 'x-grant', 'x-line', 'mcp-protocol-version', 'mcp-session-id']))) : $headers;
}, 10, 2);

add_filter('rest_exposed_cors_headers', function ($headers, $request = null) {
    return zeam_pass_own_route($request) ? array_values(array_unique(array_merge($headers, ['PAYMENT-REQUIRED', 'PAYMENT-RESPONSE', 'X-Pass-Granted-By', 'X-Pass-Ms-Remaining', 'X-Pass-Ms-Elapsed']))) : $headers;
}, 10, 2);

add_action('rest_api_init', function () {
    register_rest_route(ZEAM_PASS_NS, '/mcp', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'zeam_pass_mcp']);
    register_rest_route(ZEAM_PASS_NS, '/refund', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'zeam_pass_refund_route']);
    register_rest_route(ZEAM_PASS_NS, '/line', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'zeam_pass_line_route']);
    register_rest_route(ZEAM_PASS_NS, '/v1/(?P<tool>[a-z0-9_]+)', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'zeam_pass_http']);
    register_rest_route(ZEAM_PASS_NS, '/openapi.json', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $paths = [];
            $limit = zeam_pass_free_limit();
            foreach (zeam_pass_tools() as $name => $t) {
                $missing = $name === 'read_post' ? ['404' => ['description' => 'no published post with that id' . (empty($t['free']) ? '; nothing is charged' : '')]] : [];
                $responses = !empty($t['free'])
                    ? ['200' => ['description' => 'the result'], '400' => ['description' => 'invalid arguments']] + $missing + ($limit && empty($t['builtin']) ? ['429' => ['description' => "over {$limit} free calls an hour per address"]] : [])
                    : ['200' => ['description' => 'the result'], '400' => ['description' => 'invalid arguments; nothing is charged']] + $missing + ['402' => ['description' => 'x402 payment required'], '403' => ['description' => 'key not admitted']];
                $op = ['operationId' => $name, 'summary' => $t['description']];
                $tag = zeam_pass_price_tag($name, $t);
                if ($tag !== null) {
                    $op['x-price'] = $tag;
                }
                $paths['/v1/' . $name] = ['post' => $op + ['requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $t['inputSchema']]]], 'responses' => $responses]];
            }
            $how = ['paywall' => 'Paid per call with x402', 'gate' => 'Admitted keys only, proven by a zero-value x402 signature. Nothing is paid.', 'both' => 'Admitted keys only, paid per call with x402'][zeam_pass_mode()];
            $contact = zeam_pass_contact();
            $reach = stripos($contact, 'mailto:') === 0 ? ['email' => substr($contact, 7)] : ['url' => $contact];
            return ['openapi' => '3.0.3', 'info' => ['title' => zeam_pass_site_name(), 'version' => ZEAM_PASS_VERSION, 'description' => $how . '. MCP: ' . rest_url(ZEAM_PASS_NS . '/mcp'), 'contact' => $reach], 'servers' => [['url' => untrailingslashit(rest_url(ZEAM_PASS_NS))]], 'paths' => $paths];
        },
    ]);
});

function zeam_pass_holding()
{
    return zeam_pass_opt('hold') && !current_user_can('edit_posts');
}

function zeam_pass_held_html($post)
{
    $mcp = rest_url(ZEAM_PASS_NS . '/mcp');
    $how = [
        'paywall' => __('Agents read the full text, paid per call, over MCP at', 'zeam-pass'),
        'gate' => __('Admitted agents read the full text over MCP at', 'zeam-pass'),
        'both' => __('Admitted agents read the full text, paid per call, over MCP at', 'zeam-pass'),
    ][zeam_pass_mode()];
    return wpautop(esc_html(wp_strip_all_tags(get_the_excerpt($post))))
        . '<p>' . esc_html($how) . ' <a href="' . esc_url($mcp) . '">' . esc_html($mcp) . '</a></p>';
}

function zeam_pass_hold_text($response, $post, $request)
{
    if (!zeam_pass_holding() || !($response instanceof WP_REST_Response)) {
        return $response;
    }
    $data = $response->get_data();
    if (isset($data['content']['rendered'])) {
        $data['content']['rendered'] = zeam_pass_held_html($post);
        $data['content']['held'] = true;
        $response->set_data($data);
    }
    return $response;
}
add_filter('rest_prepare_post', 'zeam_pass_hold_text', 10, 3);

add_filter('the_content_feed', function ($content) {
    return zeam_pass_holding() ? zeam_pass_held_html(get_post()) : $content;
}, PHP_INT_MAX);

add_filter('the_excerpt_rss', function ($excerpt) {
    return zeam_pass_holding() ? wp_strip_all_tags(get_the_excerpt(get_post())) : $excerpt;
}, PHP_INT_MAX);

add_filter('option_rss_use_excerpt', function ($value) {
    return is_feed() && zeam_pass_holding() ? '1' : $value;
});

add_action('wp_head', function () {
    if (zeam_pass_payout() !== '' && (zeam_pass_mode() === 'gate' || zeam_pass_price_micro() > 0)) {
        echo '<link rel="alternate" type="application/json" title="Tools for AI agents (MCP and x402)" href="' . esc_url(rest_url(ZEAM_PASS_NS . '/openapi.json')) . '">' . "\n";
    }
});

require_once __DIR__ . '/includes/admin-actions.php';

register_activation_hook(__FILE__, 'zeam_pass_activate');
register_deactivation_hook(__FILE__, 'zeam_pass_deactivate');

function zeam_pass_activate()
{
    zeam_pass_install_tables();
    zeam_pass_ensure_keys();
    if (!wp_next_scheduled('zeam_pass_tick')) {
        wp_schedule_event(time() + 60, 'zeam_pass_minute', 'zeam_pass_tick');
    }
}

function zeam_pass_deactivate()
{
    wp_clear_scheduled_hook('zeam_pass_tick');
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['zeam_pass_minute'] = ['interval' => 60, 'display' => 'Every minute (ZEAM Pass)'];
    return $schedules;
});

add_action('zeam_pass_tick', 'zeam_pass_tick');

add_action('init', function () {
    if (get_option('zeam_pass_db') !== ZEAM_PASS_DB_VERSION) {
        zeam_pass_install_tables();
        zeam_pass_ensure_keys();
    }
    if (!wp_next_scheduled('zeam_pass_tick')) {
        wp_schedule_event(time() + 60, 'zeam_pass_minute', 'zeam_pass_tick');
    }
});
