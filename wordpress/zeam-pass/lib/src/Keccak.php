<?php

namespace ZeamPass;

final class Keccak
{
    public static function hash($bytes)
    {
        Core::assertReady();
        return \kornrunner\Keccak::hash((string) $bytes, 256, true);
    }

    public static function hex($bytes)
    {
        return Hex::fromBin(self::hash($bytes));
    }

    public static function hashHex($hex)
    {
        return Hex::fromBin(self::hash(Hex::toBin($hex)));
    }

    public static function utf8($text)
    {
        return self::hex((string) $text);
    }

    public static function selector($signature)
    {
        return substr(self::hex($signature), 0, 10);
    }
}
