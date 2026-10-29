<?php

namespace McpHub\Laravel\Security;

use RuntimeException;

/** Defense in depth on reviewed values, before any request leaves the source. */
final class Privacy
{
    public static function assertSafe($value): void
    {
        $pending = [[$value, 0]];
        $seen = 0;
        while ($pending) {
            list($item, $depth) = array_pop($pending);
            if (++$seen > 10000 || $depth > 16 || is_object($item) || is_resource($item)) {
                throw new RuntimeException('Value blocked by privacy policy.');
            }
            if (is_array($item)) {
                foreach ($item as $key => $child) {
                    $pending[] = [(string) $key, $depth + 1];
                    $pending[] = [$child, $depth + 1];
                }
            } elseif (is_string($item) || is_int($item) || is_float($item)) {
                if ((is_float($item) && ! is_finite($item)) || self::contains((string) $item)) {
                    throw new RuntimeException('Value blocked by privacy policy.');
                }
            }
        }
    }

    public static function contains(string $text): bool
    {
        if (preg_match('/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?![A-Za-z0-9])/i', $text)
            || preg_match('/(?<![\w.+-])[\w.+-]+@(?:localhost|[a-z][a-z0-9_-]+)(?![\w.-])/i', $text)
            || preg_match('/(?<!\d)(?:\+\d{8,15}|\d{10,16})(?!\d)/', $text)
            || preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $text)
            || preg_match('/\b(?:api[_ -]?key|password|passwd|authorization|bearer|secret|token)\s*[:=]\s*\S+|\b(?:sk-[a-z0-9_-]{16,}|gh[pousr]_[a-z0-9]{20,}|AKIA[A-Z0-9]{16}|hub_[a-z0-9_-]{32,}|eyJ[a-z0-9_-]{10,}\.[a-z0-9_-]{20,})/i', $text)) {
            return true;
        }
        preg_match_all('/(?<!\w)(?:\+\d{1,3}[ .-]?)?(?:\(?\d{2,4}\)?[ .-]){2,4}\d{2,4}(?!\w)/', $text, $matches);
        foreach ($matches[0] as $match) {
            if (strlen(preg_replace('/\D/', '', $match)) >= 10) {
                return true;
            }
        }
        return false;
    }
}
