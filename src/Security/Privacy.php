<?php

namespace McpHub\Laravel\Security;

use RuntimeException;

/**
 * Defense in depth on reviewed values, before any request leaves the source. The same rules as the Hub:
 * JSON numbers (amounts, counts, timestamps) are not phone numbers, ISO dates and times are not phone
 * numbers, codes that start with letters (INV-2026-11-0001) are identifiers, and a long digit run that
 * does not start like a phone number (0, 62 or +) is a barcode or an ID. Emails, credentials,
 * +country and local 08… numbers and 16-digit national IDs still match.
 */
final class Privacy
{
    const DATE_TIME = '/\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?)?/';

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
            } elseif (is_string($item) ? self::contains($item) : ((is_int($item) || is_float($item)) && self::numberContains($item))) {
                throw new RuntimeException('Value blocked by privacy policy.');
            }
        }
    }

    /** Whether one scalar output value looks personal; such a value is hidden rather than sent. */
    public static function looksPersonal($value): bool
    {
        if (is_string($value)) {
            return self::contains($value);
        }
        return (is_int($value) || is_float($value)) && self::numberContains($value);
    }

    public static function contains(string $text): bool
    {
        if (preg_match('/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?![A-Za-z0-9])/i', $text)
            || preg_match('/(?<![\w.+-])[\w.+-]+@(?:localhost|[a-z][a-z0-9_-]+)(?![\w.-])/i', $text)
            || preg_match('/(?<![A-Za-z0-9])\d{16}(?!\d)/', $text)
            || preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $text)
            || preg_match('/\b(?:api[_ -]?key|password|passwd|authorization|bearer|secret|token)\s*[:=]\s*\S+|\b(?:sk-[a-z0-9_-]{16,}|gh[pousr]_[a-z0-9]{20,}|AKIA[A-Z0-9]{16}|hub_[a-z0-9_-]{32,}|eyJ[a-z0-9_-]{10,}\.[a-z0-9_-]{20,})/i', $text)) {
            return true;
        }
        return self::phone($text);
    }

    /** A JSON number is an amount, count or timestamp, never a phone; a 16-digit one may be a national ID. */
    public static function numberContains($value): bool
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return true;
            }
            if (floor($value) != $value || abs($value) >= 1e18) {
                return abs($value) >= 1e15 && abs($value) < 1e16;
            }
            $value = (int) $value;
        }
        return strlen((string) abs($value)) === 16;
    }

    private static function phone(string $text): bool
    {
        $text = (string) preg_replace(self::DATE_TIME, ' ', $text);
        if (preg_match_all('/(?<![A-Za-z0-9])(\+?)(\d{10,15})(?!\d)/', $text, $runs, PREG_SET_ORDER)) {
            foreach ($runs as $run) {
                // Long runs that do not start like a phone number are barcodes or IDs.
                if ($run[1] !== '' || strlen($run[2]) < 13 || strpos($run[2], '0') === 0 || strpos($run[2], '62') === 0) {
                    return true;
                }
            }
        }
        preg_match_all('/(?<!\w)(?:\+\d{1,3}[ .-]?)?(?:\(?\d{2,4}\)?[ .-]){2,4}\d{2,4}(?!\w)/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $pair) {
            $offset = $pair[1];
            // A run right after letters and a separator (INV-2026-11-0001, PO/2026/11/0001) is a code.
            if (preg_match('/[A-Za-z][-_\/#.]?$/', substr($text, max(0, $offset - 2), min(2, $offset)))) {
                continue;
            }
            if (strlen((string) preg_replace('/\D/', '', $pair[0])) >= 10) {
                return true;
            }
        }
        return false;
    }
}
