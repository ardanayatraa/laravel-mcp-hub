<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Settings;
use McpHub\Laravel\Tests\Fixtures\User;

class SettingsTest extends TestCase
{
    private $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($this->originalStorage.'/framework/testing/settings-'.bin2hex(random_bytes(8)));
        config(['mcp-hub.enabled' => true, 'mcp-hub.app_key' => 'your_app',
            'mcp-hub.url' => 'https://hub.test/mcp', 'mcp-hub.source_url' => 'https://source.test',
            'mcp-hub.client_key' => 'private-settings-client-canary',
            'mcp-hub.app_token' => str_repeat('a', 32), 'mcp-hub.delegation_secret' => str_repeat('d', 32)]);
        User::query()->create(['id' => 1, 'name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'unused']);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        parent::tearDown();
    }

    public function test_settings_are_closed_by_default_even_for_a_signed_in_user(): void
    {
        $this->get('/mcp-hub')->assertStatus(403);
        $this->postJson('/api/ai/v1/settings', [])->assertStatus(404);
        $this->actingAs(User::query()->findOrFail(1));
        $this->get('/mcp-hub')->assertStatus(403);
    }

    public function test_authorized_page_contains_no_secret_or_business_record(): void
    {
        $this->allow();
        $response = $this->get('/mcp-hub')->assertStatus(200)->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringNotContainsString('private-settings-client-canary', $response->getContent());
        $this->assertStringNotContainsString(str_repeat('d', 32), $response->getContent());
        $this->assertStringContainsString('orders', $response->getContent());
        DB::enableQueryLog();
        app(Settings::class)->snapshot();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
    }

    public function test_saved_field_permissions_immediately_restrict_manifest_and_lookup(): void
    {
        $this->allow();
        $input = $this->input();
        $input['resources']['orders']['fields'] = ['status'];
        $response = $this->postJson('/mcp-hub/settings', $input)->assertStatus(200);
        $this->assertTrue($response->json()['data']['enabled']);
        $resource = app(Registry::class)->resources()['orders'];
        $this->assertSame(['status' => 'string'], $resource['fields']);
        $this->assertNull($resource['lookup']);
        $this->assertSame(['status' => ['paid', 'pending']], $resource['filters']);
        $this->assertStringNotContainsString('private-settings-client-canary', $response->getContent());
    }

    public function test_resources_can_be_revoked_without_changing_the_developer_registration(): void
    {
        $this->allow();
        $input = $this->input();
        $input['resources']['orders']['enabled'] = false;
        $this->postJson('/mcp-hub/settings', $input)->assertStatus(200);
        $this->assertSame([], app(Registry::class)->resources());
        $this->assertArrayHasKey('orders', app(Registry::class)->declaredResources());
    }

    public function test_manager_cannot_expand_fields_operations_or_resource_aliases(): void
    {
        $this->allow();
        foreach (['fields', 'operations', 'resource'] as $case) {
            $input = $this->input();
            if ($case === 'fields') $input['resources']['orders']['fields'][] = 'email';
            if ($case === 'operations') $input['resources']['orders']['operations'][] = 'delete';
            if ($case === 'resource') $input['resources']['accounts'] = $input['resources']['orders'];
            $this->postJson('/mcp-hub/settings', $input)->assertStatus(422);
        }
        $this->assertSame([], app(Connection::class)->stored());
    }

    public function test_stale_permissions_cannot_overwrite_another_save(): void
    {
        $this->allow();
        $input = $this->input();
        $changed = $input;
        $changed['resources']['orders']['fields'] = ['title'];
        $this->postJson('/mcp-hub/settings', $changed)->assertStatus(200);
        $this->postJson('/mcp-hub/settings', $input)->assertStatus(409);
        $this->assertSame(['title' => 'string'], app(Registry::class)->resources()['orders']['fields']);
    }

    public function test_explicit_developer_disable_cannot_be_overridden(): void
    {
        $this->allow();
        config(['mcp-hub.enabled' => false]);
        $input = $this->input();
        $input['enabled'] = true;
        $this->postJson('/mcp-hub/settings', $input)->assertStatus(403);
        $this->assertFalse(app(Connection::class)->get('enabled'));
    }

    public function test_settings_mutations_require_csrf(): void
    {
        $this->allow();
        $this->withHeaders(['X-CSRF-TOKEN' => ''])->postJson('/mcp-hub/settings', $this->input())->assertStatus(419);
        $this->assertSame([], app(Connection::class)->stored());
    }

    public function test_unsafe_urls_do_not_expose_credentials_in_the_page(): void
    {
        $this->allow();
        config(['mcp-hub.url' => 'https://user:private-url-token@hub.test/mcp?key=private-query-token']);
        $response = $this->get('/mcp-hub')->assertStatus(200);
        $this->assertStringNotContainsString('private-url-token', $response->getContent());
        $this->assertStringNotContainsString('private-query-token', $response->getContent());
    }

    private function allow(): void
    {
        Gate::define('mcp-hub.manage', function ($user) { return $user->getAuthIdentifier() === 1; });
        $this->actingAs(User::query()->findOrFail(1));
        $this->withSession(['_token' => 'dummy-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'dummy-csrf']);
    }

    private function input(): array
    {
        $snapshot = app(Settings::class)->snapshot();
        return ['revision' => $snapshot['revision'], 'enabled' => $snapshot['enabled'],
            'resources' => ['orders' => $snapshot['resources']['orders']['grant']]];
    }
}
