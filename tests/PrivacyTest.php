<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\Security\FieldPrivacy;
use McpHub\Laravel\Security\Privacy;
use McpHub\Laravel\Tests\Fixtures\Order;
use RuntimeException;

class PrivacyTest extends TestCase
{
    public function test_sensitive_aliases_are_blocked_without_blocking_ordinary_names(): void
    {
        foreach (['full_name', 'customerFirstName', 'shipping_address', 'APIKey', 'smsOTP', 'dob',
            'bank_account_number', 'passport_no', 'userSSN', 'card_cvc'] as $field) {
            $this->assertTrue(FieldPrivacy::blocked($field), $field);
        }
        foreach (['shipping_status', 'spinner', 'is_pinned', 'topic', 'provider', 'created_at', 'account_type'] as $field) {
            $this->assertFalse(FieldPrivacy::blocked($field), $field);
        }
    }

    public function test_personal_values_and_credentials_are_blocked_even_in_safe_named_fields(): void
    {
        foreach (['canary@example.test', 'canary@localhost', '+6281234567890', '555-11-2222',
            'password=private-canary', 'api_key: private-canary', '0812 3456 7890'] as $value) {
            $this->assertTrue(Privacy::contains($value));
        }
        foreach (['Order pending', '2026-10-29T01:00:00Z', 'record_1', 'prop_'.str_repeat('a', 48)] as $value) {
            $this->assertFalse(Privacy::contains($value), $value);
        }
    }

    public function test_recursive_and_oversized_trees_fail_closed(): void
    {
        $tree = [];
        $tree['self'] = &$tree;
        $this->expectException(RuntimeException::class);
        Privacy::assertSafe($tree);
    }

    public function test_source_rejects_personal_values_before_they_leave_laravel(): void
    {
        config(['mcp-hub.enabled' => true, 'mcp-hub.app_key' => 'your_app', 'mcp-hub.source_url' => 'http://source.test',
            'mcp-hub.app_token' => str_repeat('a', 32), 'mcp-hub.delegation_secret' => str_repeat('d', 32),
            'mcp-hub.require_https' => false]);
        \McpHub\Laravel\Tests\Fixtures\User::query()->create(['id' => 1, 'name' => 'Demo',
            'email' => 'demo@example.test', 'password' => 'unused']);
        Order::query()->create(['user_id' => 1, 'title' => 'private-canary@example.test', 'status' => 'paid',
            'total' => 10, 'private_note' => 'unused']);
        $claims = ['app_key' => 'your_app', 'user_ref' => 'demo_user_1', 'tenant_id' => 'demo_account_1',
            'aud' => 'http://source.test', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16))];
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $encode = function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
        $response = $this->withHeaders(['Authorization' => 'Bearer '.str_repeat('a', 32),
            'X-Hub-Assertion' => $encode($json).'.'.$encode(hash_hmac('sha256', $json, str_repeat('d', 32), true))])
            ->getJson('/api/ai/v1/orders/list')->assertStatus(403);
        $this->assertStringNotContainsString('private-canary', $response->getContent());
    }
}
