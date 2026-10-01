<?php

namespace ZeamPass\Settlement;

interface Store
{
    public function get($channelId);

    public function update($channelId, callable $fn);

    public function list();
}
