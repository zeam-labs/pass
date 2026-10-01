<?php

namespace ZeamPass\Gate;

final class Http
{
    private $timeout;

    public function __construct($timeout = 20)
    {
        $this->timeout = (float) $timeout;
    }

    public function __invoke($method, $url, array $headers = [], $body = null)
    {
        return \ZeamPass\Transport::request($method, $url, $headers, $body, $this->timeout);
    }
}
