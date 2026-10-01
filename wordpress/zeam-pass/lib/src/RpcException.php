<?php

namespace ZeamPass;

final class RpcException extends \RuntimeException
{
    public $rpcCode;

    public function __construct($message, $rpcCode = null)
    {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
    }
}
