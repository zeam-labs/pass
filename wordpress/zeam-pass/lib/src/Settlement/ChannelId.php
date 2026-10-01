<?php

namespace ZeamPass\Settlement;

final class ChannelId
{
    public static function key($channelId)
    {
        $id = strtolower((string) $channelId);
        if (!preg_match('/^0x[0-9a-f]{64}$/D', $id)) {
            throw new \InvalidArgumentException('a channel id is 32 bytes of hex');
        }
        return $id;
    }
}
