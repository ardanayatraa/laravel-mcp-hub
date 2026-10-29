<?php

namespace McpHub\Laravel\Security;

final class FieldPrivacy
{
    private const BLOCKED = [
        'email', 'e_mail', 'phone', 'phone_number', 'mobile', 'password', 'passwd',
        'token', 'secret', 'api_key', 'authorization', 'national_id', 'nik', 'ssn',
        'full_name', 'first_name', 'last_name', 'address', 'dob', 'date_of_birth',
        'user_id', 'tenant_id', 'otp', 'one_time_password', 'pin', 'cvv', 'cvc',
        'verification_code', 'auth_code', 'security_code', 'msisdn', 'card_number',
        'credit_card', 'iban', 'account_number', 'passport', 'npwp', 'ktp',
    ];

    public static function blocked(string $name): bool
    {
        foreach (explode('.', $name) as $part) {
            $split = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $part);
            $split = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $split);
            $words = preg_split('/[_-]+/', strtolower($split), -1, PREG_SPLIT_NO_EMPTY);
            $candidates = array_merge([strtolower($part)], $words);
            foreach ([2, 3] as $size) {
                for ($index = 0; $index + $size <= count($words); ++$index) {
                    $candidates[] = implode('_', array_slice($words, $index, $size));
                }
            }
            if (array_intersect($candidates, self::BLOCKED)) {
                return true;
            }
        }
        return false;
    }
}
