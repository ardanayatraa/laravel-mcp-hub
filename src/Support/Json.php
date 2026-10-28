<?php

namespace McpHub\Laravel\Support;

use RuntimeException;

final class Json
{
    public static function encode($value, int $flags = 0): string
    {
        $result = json_encode($value, $flags);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON encoding failed.');
        }
        return $result;
    }

    public static function decode(string $value, int $depth = 16)
    {
        $result = json_decode($value, true, $depth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON decoding failed.');
        }
        return $result;
    }
}
