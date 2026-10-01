<?php

namespace ZeamPass;

final class Rpc
{
    private $url;
    private $timeout;
    private $connectTimeout;
    private $id = 0;

    public function __construct($url, $timeout = 10, $connectTimeout = 5)
    {
        if (!preg_match('#^https?://#i', (string) $url)) {
            throw new \InvalidArgumentException('an RPC URL starts with http:// or https://');
        }
        $this->url = $url;
        $this->timeout = (float) $timeout;
        $this->connectTimeout = (float) $connectTimeout;
    }

    public function call($method, array $params = [])
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]);
        try {
            $r = Transport::request('POST', $this->url, ['content-type' => 'application/json', 'accept' => 'application/json'], $body, $this->timeout, $this->connectTimeout);
        } catch (\RuntimeException $e) {
            throw new RpcException(esc_html('RPC request failed: ' . \ZeamPass\message($e)));
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RpcException(esc_html("RPC answered HTTP {$r['status']}"));
        }
        $raw = $r['body'];
        $reply = json_decode($raw, true);
        if (!is_array($reply)) {
            throw new RpcException(esc_html("$method: the RPC answered with something that is not JSON"));
        }
        if (isset($reply['error'])) {
            $e = $reply['error'];
            throw new RpcException(esc_html("$method: " . (isset($e['message']) ? $e['message'] : 'RPC error')), isset($e['code']) ? (int) $e['code'] : null);
        }
        if (!array_key_exists('result', $reply)) {
            throw new RpcException(esc_html("$method: the RPC answer has no result"));
        }
        return $reply['result'];
    }

    public function chainId()
    {
        return (int) Num::dec($this->call('eth_chainId'));
    }

    public function ethCall($to, $data, $block = 'latest', $from = null)
    {
        $tx = ['to' => Address::checksum($to), 'data' => Hex::lower($data)];
        if ($from !== null) {
            $tx['from'] = Address::checksum($from);
        }
        return $this->call('eth_call', [$tx, $block]);
    }

    public function transactionReceipt($hash)
    {
        if (!Hex::isHex($hash, 32)) {
            throw new \InvalidArgumentException('a transaction hash is 32 bytes');
        }
        return $this->call('eth_getTransactionReceipt', [strtolower($hash)]);
    }
}
