<?php

namespace ZeamPass\Gate;

interface Store
{
    public function get($key);

    public function update($key, callable $change);
}
