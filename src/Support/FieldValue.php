<?php

namespace McpHub\Laravel\Support;

use McpHub\Laravel\Security\Privacy;
use RuntimeException;

final class FieldValue
{
    public static function present($value, string $type)
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'integer' && is_numeric($value) && (int) $value == $value) {
            $value = (int) $value;
        } elseif ($type === 'number' && is_numeric($value) && is_finite((float) $value)) {
            $value = (float) $value;
        } elseif ($type === 'boolean' && in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            $value = (bool) $value;
        } elseif ($type === 'datetime') {
            $value = \Illuminate\Support\Carbon::parse($value)->toAtomString();
        } elseif ($type === 'string' && is_scalar($value) && strlen((string) $value) <= 512) {
            $value = (string) $value;
        } else {
            throw new RuntimeException('Resource field value is invalid.');
        }
        Privacy::assertSafe($value);
        return $value;
    }
}
