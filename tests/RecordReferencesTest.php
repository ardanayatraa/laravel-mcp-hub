<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\Security\Actor;
use McpHub\Laravel\Security\Privacy;
use McpHub\Laravel\Security\RecordReferences;
use McpHub\Laravel\Tests\Fixtures\User;
use RuntimeException;

class RecordReferencesTest extends TestCase
{
    public function test_numeric_and_uuid_identifiers_are_opaque_and_privacy_safe(): void
    {
        $references = app(RecordReferences::class);
        $actor = new Actor(new User(), 'actor_a', 'tenant_a');
        foreach (['1234567890123456', 'f79c36e6-9221-4763-948d-b04dce1838fc'] as $key) {
            $ref = $references->encode('orders', $actor, $key);
            $this->assertTrue(strlen($ref) <= 1024);
            $this->assertSame(1, preg_match('/^ref_[a-p]+$/D', $ref));
            $this->assertSame($key, $references->decode('orders', $actor, $ref));
            $this->assertStringNotContainsString($key, $ref);
            Privacy::assertSafe($ref);
        }
    }

    public function test_actor_tenant_resource_and_tampering_are_rejected(): void
    {
        $references = app(RecordReferences::class);
        $actor = new Actor(new User(), 'actor_a', 'tenant_a');
        $ref = $references->encode('orders', $actor, 1);
        $failures = [
            ['orders', new Actor(new User(), 'actor_b', 'tenant_a'), $ref],
            ['orders', new Actor(new User(), 'actor_a', 'tenant_b'), $ref],
            ['other', $actor, $ref],
            ['orders', $actor, substr($ref, 0, -2).'aa'],
            ['orders', $actor, 'record_1'],
        ];
        foreach ($failures as $case) {
            try {
                $references->decode($case[0], $case[1], $case[2]);
                $this->fail('Unbound reference was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('Record access denied.', $error->getMessage());
            }
        }
    }

    public function test_legacy_format_requires_explicit_configuration(): void
    {
        config(['mcp-hub.record_references' => 'legacy']);
        $actor = new Actor(new User(), 'actor_a', 'tenant_a');
        $this->assertSame('record_1', app(RecordReferences::class)->encode('orders', $actor, 1));
        $this->assertSame('1', app(RecordReferences::class)->decode('orders', $actor, 'record_1'));
    }

    public function test_reference_cannot_cross_source_or_environment_even_with_a_shared_app_key(): void
    {
        $actor = new Actor(new User(), 'actor_a', 'tenant_a');
        config(['mcp-hub.environment' => 'staging', 'mcp-hub.source_url' => 'https://staging.example.com']);
        $ref = app(RecordReferences::class)->encode('orders', $actor, 1);
        foreach ([['production', 'https://staging.example.com'], ['staging', 'https://app.example.com']] as $context) {
            config(['mcp-hub.environment' => $context[0], 'mcp-hub.source_url' => $context[1]]);
            try {
                app(RecordReferences::class)->decode('orders', $actor, $ref);
                $this->fail('Cross-installation reference was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('Record access denied.', $error->getMessage());
            }
        }
    }
}
