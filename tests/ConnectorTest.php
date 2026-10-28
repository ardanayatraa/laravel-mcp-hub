<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;
use McpHub\Laravel\Connection;
use McpHub\Laravel\HubClient;

class ConnectorTest extends TestCase
{

    private string $token = 'dummy-source-token-at-least-32-characters';
    private string $secret = 'dummy-delegation-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp-hub.enabled' => true, 'mcp-hub.app_key' => 'your_app',
            'mcp-hub.source_url' => 'http://source.test', 'mcp-hub.app_token' => $this->token,
            'mcp-hub.delegation_secret' => $this->secret, 'mcp-hub.require_https' => false,
            'mcp-hub.url' => 'http://127.0.0.1:18130/mcp', 'mcp-hub.client_key' => 'dummy-client-key',
            'cache.default' => 'array']);
        foreach ([1, 2] as $id) {
            User::query()->create(['id' => $id, 'name' => 'Dummy '.$id, 'email' => $id.'@example.test', 'password' => 'unused']);
            Order::query()->create(['user_id' => $id, 'title' => 'Order '.$id, 'status' => 'paid',
                'total' => $id * 100, 'private_note' => 'private-canary-'.$id]);
        }
    }

    public function test_package_default_does_not_require_an_application_model(): void
    {
        $defaults = require __DIR__.'/../config/mcp-hub.php';
        $this->assertSame([], $defaults['models']);
        config(['mcp-hub.models' => []]);
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')
            ->assertOk()->assertJsonPath('modules', []);
    }

    public function test_health_requires_service_credential(): void
    {
        $this->getJson('/api/ai/v1/health')->assertUnauthorized();
        $this->withToken($this->token)->getJson('/api/ai/v1/health')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJson(['status' => 'ok']);
    }

    public function test_manifest_contains_only_reviewed_metadata(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertOk();
        $this->assertSame('application', $response['managed_by']);
        $this->assertSame('your_app.orders.list', $response['modules'][0]['read_endpoints'][0]['tool_key']);
        $this->assertStringNotContainsString('private_note', $response->getContent());
        $this->assertStringNotContainsString('private-canary', $response->getContent());
    }

    public function test_source_reads_only_the_current_actors_records(): void
    {
        foreach ([1, 2] as $id) {
            $response = $this->read($id)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.title', 'Order '.$id);
            $this->assertSame(['ref', 'tenant_id', 'title', 'status', 'total'], array_keys($response['data'][0]));
            $this->assertStringNotContainsString('private-canary', $response->getContent());
        }
    }

    public function test_count_and_lookup_are_scoped(): void
    {
        $this->read(1, 'count')->assertOk()->assertJsonPath('data.0.count', 1);
        $this->read(1, 'get?title=Order%202')->assertOk()->assertJsonPath('data', []);
        $this->read(1, 'get?title=Order%201')->assertOk()->assertJsonPath('data.0.title', 'Order 1');
    }

    public function test_missing_invalid_and_expired_assertions_are_denied(): void
    {
        $this->withToken($this->token)->getJson('/api/ai/v1/orders/list')->assertForbidden();
        foreach ([['exp' => time() - 1], ['aud' => 'http://other.test'], ['app_key' => 'other_app'],
            ['tenant_id' => 'demo_account_2'], ['user_ref' => 'untrusted'], ['exp' => time() + 151]] as $override) {
            $this->read(1, 'list', $override)->assertForbidden();
        }
        $this->withToken($this->token)->withHeader('X-Hub-Assertion', 'bad.signature')
            ->getJson('/api/ai/v1/orders/list')->assertForbidden();
    }

    public function test_assertion_replay_is_denied(): void
    {
        $assertion = $this->assertion(1);
        $this->withToken($this->token)->withHeader('X-Hub-Assertion', $assertion)
            ->getJson('/api/ai/v1/orders/list')->assertOk();
        $this->withToken($this->token)->withHeader('X-Hub-Assertion', $assertion)
            ->getJson('/api/ai/v1/orders/list')->assertForbidden();
    }

    public function test_inactive_account_is_denied(): void
    {
        User::query()->whereKey(1)->update(['active' => false]);
        $this->read(1)->assertForbidden();
    }

    public function test_query_cannot_supply_actor_or_unregistered_filters(): void
    {
        $this->read(1, 'list?user_id=2')->assertForbidden();
        $this->read(1, 'list?limit=51')->assertUnprocessable();
        $this->read(1, 'list?status=unreviewed')->assertUnprocessable();
    }

    public function test_revocation_is_effective_on_the_next_request(): void
    {
        $this->read(1)->assertOk();
        config(['mcp-hub.models.orders.read' => false]);
        $this->read(1)->assertForbidden();
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertJsonPath('modules', []);
    }

    public function test_sensitive_fields_and_missing_policies_fail_closed(): void
    {
        config(['mcp-hub.models.orders.fields.email' => 'string']);
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertUnprocessable();
        config(['mcp-hub.models' => config('mcp-hub-test.models'), 'mcp-hub.models.orders.policy' => null]);
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertUnprocessable();
    }

    public function test_transport_preserves_scoped_credentials_and_response_id(): void
    {
        Http::fake(fn ($request) => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['tools' => []]]));
        $this->assertSame(['tools' => []], app(HubClient::class)->rpc('tools/list', [], ['Authorization' => 'unsafe']));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dummy-client-key')
            && $request->hasHeader('Origin', 'http://source.test') && $request['method'] === 'tools/list');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 999, 'result' => ['tools' => []]]));
        $this->expectExceptionMessage('MCP Hub request failed.');
        app(HubClient::class)->rpc('tools/list');
    }

    public function test_transport_reads_sse_and_sanitizes_upstream_errors(): void
    {
        Http::fake(fn ($request) => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => $request['id'],
            'result' => ['tools' => []]])."\n\n", 200, ['Content-Type' => 'text/event-stream']));
        $this->assertSame(['tools' => []], app(HubClient::class)->rpc('tools/list'));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => Http::response(['credential' => 'sensitive-canary'], 500));
        $this->expectExceptionMessage('MCP Hub request failed.');
        app(HubClient::class)->rpc('tools/list');
    }

    public function test_connection_storage_is_encrypted(): void
    {
        $original = $this->app->storagePath();
        $temporary = $original.'/framework/testing/connection-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($temporary);
        try {
            app(Connection::class)->save(['client_key' => 'storage-canary']);
            $contents = file_get_contents($temporary.'/app/mcp-hub/connection.enc');
            $this->assertStringNotContainsString('storage-canary', $contents);
            config(['mcp-hub.client_key' => null]);
            $this->assertSame('storage-canary', app(Connection::class)->get('client_key'));
        } finally {
            $this->app->useStoragePath($original);
        }
    }


    public function test_production_rejects_an_in_memory_replay_cache(): void
    {
        $this->app['env'] = 'production';
        config(['cache.default' => 'array']);
        $this->read(1)->assertForbidden();
    }

    public function test_pagination_names_cannot_be_registered_as_filters_or_lookup(): void
    {
        config(['mcp-hub.models.orders.fields.limit' => 'string',
            'mcp-hub.models.orders.filters.limit' => ['999999999999999999']]);
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertUnprocessable();
        config(['mcp-hub.models' => config('mcp-hub-test.models'),
            'mcp-hub.models.orders.fields.cursor' => 'string', 'mcp-hub.models.orders.lookup' => 'cursor']);
        $this->withToken($this->token)->getJson('/api/ai/v1/capabilities')->assertUnprocessable();
    }

    public function test_plain_http_hub_is_refused_outside_local_environments(): void
    {
        $this->app['env'] = 'production';
        Http::preventStrayRequests();
        $this->expectExceptionMessage('MCP Hub connection is not configured.');
        app(HubClient::class)->rpc('tools/list');
    }

    private function read(int $id, string $path = 'list', array $override = [])
    {
        return $this->withToken($this->token)->withHeader('X-Hub-Assertion', $this->assertion($id, $override))
            ->getJson('/api/ai/v1/orders/'.$path);
    }

    private function assertion(int $id, array $override = []): string
    {
        $claims = array_replace(['app_key' => 'your_app', 'user_ref' => 'demo_user_'.$id, 'tenant_id' => 'demo_account_'.$id,
            'aud' => 'http://source.test', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16))], $override);
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        return $encode($json).'.'.$encode(hash_hmac('sha256', $json, $this->secret, true));
    }
}
