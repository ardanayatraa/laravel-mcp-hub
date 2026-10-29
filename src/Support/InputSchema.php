<?php

namespace McpHub\Laravel\Support;

use InvalidArgumentException;

/** Closed, bounded scalar schemas shared by the manifest and source validation. */
final class InputSchema
{
    public static function definition(array $schema): void
    {
        $fields = $schema['properties'] ?? null;
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false
            || ! is_array($fields) || count($fields) > 30
            || array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties'])) {
            throw new InvalidArgumentException('Invalid command input schema.');
        }
        $required = $schema['required'] ?? [];
        if (! is_array($required) || array_filter($required, function ($name) { return ! is_string($name); })
            || count($required) !== count(array_unique($required))
            || array_diff($required, array_keys($fields))) {
            throw new InvalidArgumentException('Invalid command required fields.');
        }
        foreach ($fields as $name => $field) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/D', $name)
                || ! is_array($field)
                || array_diff(array_keys($field), ['type', 'minLength', 'maxLength', 'minimum', 'maximum', 'enum'])) {
                throw new InvalidArgumentException('Invalid command field.');
            }
            $types = (array) ($field['type'] ?? []);
            if (count($types) < 1 || count($types) > 2
                || array_filter($types, function ($type) { return ! is_string($type); })
                || count($types) !== count(array_unique($types))
                || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null'])
                || count(array_intersect($types, ['string', 'integer', 'number', 'boolean'])) !== 1
                || (count($types) === 2 && ! in_array('null', $types, true))) {
                throw new InvalidArgumentException('Invalid command field type.');
            }
            if (in_array('string', $types, true)) {
                $min = $field['minLength'] ?? 0;
                $max = $field['maxLength'] ?? null;
                $cap = in_array($name, ['ref', 'record_ref', 'proposal_ref'], true) ? 1024 : 512;
                if (! is_int($min) || ! is_int($max) || $min < 0 || $max < $min || $max > $cap) {
                    throw new InvalidArgumentException('Command strings must be bounded.');
                }
            } elseif (isset($field['minLength']) || isset($field['maxLength'])) {
                throw new InvalidArgumentException('Invalid string constraint.');
            }
            foreach (['minimum', 'maximum'] as $bound) {
                if (isset($field[$bound]) && ((! is_int($field[$bound]) && ! is_float($field[$bound]))
                    || ! is_finite((float) $field[$bound]))) {
                    throw new InvalidArgumentException('Invalid numeric constraint.');
                }
            }
            if (isset($field['minimum'], $field['maximum']) && $field['minimum'] > $field['maximum']) {
                throw new InvalidArgumentException('Invalid numeric bounds.');
            }
            if (isset($field['enum']) && (! is_array($field['enum']) || $field['enum'] === [] || count($field['enum']) > 30)) {
                throw new InvalidArgumentException('Invalid command choices.');
            }
            foreach ($field['enum'] ?? [] as $value) {
                if (! self::matches($value, $field)) {
                    throw new InvalidArgumentException('Invalid command choice.');
                }
                \McpHub\Laravel\Security\Privacy::assertSafe($value);
            }
        }
    }

    public static function validate(array $schema, array $input): array
    {
        self::definition($schema);
        if (array_diff(array_keys($input), array_keys($schema['properties']))
            || array_diff($schema['required'] ?? [], array_keys($input))) {
            throw new InvalidArgumentException('Command input is invalid.');
        }
        foreach ($input as $name => $value) {
            $field = $schema['properties'][$name];
            if (! self::matches($value, $field)
                || (isset($field['enum']) && ! in_array($value, $field['enum'], true))) {
                throw new InvalidArgumentException('Command input is invalid.');
            }
        }
        return $input;
    }

    private static function matches($value, array $field): bool
    {
        $types = (array) $field['type'];
        if ($value === null) {
            return in_array('null', $types, true);
        }
        if (is_string($value) && in_array('string', $types, true)) {
            return preg_match('//u', $value) === 1 && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
                && mb_strlen($value, 'UTF-8') >= ($field['minLength'] ?? 0)
                && mb_strlen($value, 'UTF-8') <= ($field['maxLength'] ?? 512);
        }
        if (is_bool($value)) {
            return in_array('boolean', $types, true);
        }
        if ((is_int($value) && (in_array('integer', $types, true) || in_array('number', $types, true)))
            || (is_float($value) && in_array('number', $types, true))) {
            return is_finite((float) $value) && $value >= ($field['minimum'] ?? -1.0e15)
                && $value <= ($field['maximum'] ?? 1.0e15);
        }
        return false;
    }
}
