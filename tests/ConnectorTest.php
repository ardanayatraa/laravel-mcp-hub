<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use McpHub\Laravel\Connection;
use McpHub\Laravel\HubClient;

class ConnectorTest extends TestCase
{

    private $token = 'dummy-source-token-at-least-32-characters';
    private $secret = 'dummy-delegation-secret-at-least-32-characters';

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
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(200);
        $this->assertSame([], $response->json()['modules']);
    }

    public function test_health_requires_service_credential(): void
    {
        $this->getJson('/api/ai/v1/health')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/health')->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJson(['status' => 'ok']);
    }

    public function test_manifest_contains_only_reviewed_metadata(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(200);
        $this->assertSame('application', $response->json()['managed_by']);
        $this->assertSame('your_app.orders.list', $response->json()['modules'][0]['read_endpoints'][0]['tool_key']);
        $this->assertStringNotContainsString('private_note', $response->getContent());
        $this->assertStringNotContainsString('private-canary', $response->getContent());
    }

    public function test_source_reads_only_the_current_actors_records(): void
    {
        foreach ([1, 2] as $id) {
            $response = $this->read($id)->assertStatus(200);
            $this->assertCount(1, $response->json()['data']);
            $this->assertSame('Order '.$id, $response->json()['data'][0]['title']);
            $this->assertSame(['ref', 'tenant_id', 'title', 'status', 'total'], array_keys($response->json()['data'][0]));
            $this->assertStringNotContainsString('private-canary', $response->getContent());
        }
    }

    public function test_count_and_lookup_are_scoped(): void
    {
        $count = $this->read(1, 'count')->assertStatus(200);
        $this->assertSame(1, $count->json()['data'][0]['count']);
        $missing = $this->read(1, 'get?title=Order%202')->assertStatus(200);
        $this->assertSame([], $missing->json()['data']);
        $found = $this->read(1, 'get?title=Order%201')->assertStatus(200);
        $this->assertSame('Order 1', $found->json()['data'][0]['title']);
    }

    public function test_missing_invalid_and_expired_assertions_are_denied(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/orders/list')->assertStatus(403);
        foreach ([['exp' => time() - 1], ['aud' => 'http://other.test'], ['app_key' => 'other_app'],
            ['tenant_id' => 'demo_account_2'], ['user_ref' => 'untrusted'], ['exp' => time() + 151]] as $override) {
            $this->read(1, 'list', $override)->assertStatus(403);
        }
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->withHeaders(['X-Hub-Assertion' => 'bad.signature'])
            ->getJson('/api/ai/v1/orders/list')->assertStatus(403);
    }

    public function test_assertion_replay_is_denied(): void
    {
        $assertion = $this->assertion(1);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->withHeaders(['X-Hub-Assertion' => $assertion])
            ->getJson('/api/ai/v1/orders/list')->assertStatus(200);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->withHeaders(['X-Hub-Assertion' => $assertion])
            ->getJson('/api/ai/v1/orders/list')->assertStatus(403);
    }

    public function test_inactive_account_is_denied(): void
    {
        User::query()->whereKey(1)->update(['active' => false]);
        $this->read(1)->assertStatus(403);
    }

    public function test_query_cannot_supply_actor_or_unregistered_filters(): void
    {
        $this->read(1, 'list?user_id=2')->assertStatus(403);
        $this->read(1, 'list?limit=51')->assertStatus(422);
        $this->read(1, 'list?status=unreviewed')->assertStatus(422);
    }

    public function test_revocation_is_effective_on_the_next_request(): void
    {
        $this->read(1)->assertStatus(200);
        config(['mcp-hub.models.orders.read' => false]);
        $this->read(1)->assertStatus(403);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(200);
        $this->assertSame([], $response->json()['modules']);
    }

    public function test_sensitive_fields_and_missing_policies_fail_closed(): void
    {
        config(['mcp-hub.models.orders.fields.email' => 'string']);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(422);
        config(['mcp-hub.models' => config('mcp-hub-test.models'), 'mcp-hub.models.orders.policy' => null]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(422);
    }

    public function test_transport_preserves_scoped_credentials_and_response_id(): void
    {
        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]])),
            new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 999, 'result' => ['tools' => []]])),
        ], $history);
        $this->assertSame(['tools' => []], $client->rpc('tools/list', [], ['Authorization' => 'unsafe']));
        $request = $history[0]['request'];
        $this->assertSame('Bearer dummy-client-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('http://source.test', $request->getHeaderLine('Origin'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('tools/list', json_decode((string) $request->getBody(), true)['method']);
        $this->expectExceptionMessage('MCP Hub request failed.');
        $client->rpc('tools/list');
    }

    public function test_transport_reads_sse_and_sanitizes_upstream_errors(): void
    {
        $history = [];
        $client = $this->clientWithResponses([
            new Response(200, ['Content-Type' => 'text/event-stream'], 'data: '.json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]])."\n\n"),
            new Response(500, [], json_encode(['credential' => 'sensitive-canary'])),
        ], $history);
        $this->assertSame(['tools' => []], $client->rpc('tools/list'));
        $this->expectExceptionMessage('MCP Hub request failed.');
        $client->rpc('tools/list');
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
        $this->read(1)->assertStatus(403);
    }

    public function test_pagination_names_cannot_be_registered_as_filters_or_lookup(): void
    {
        config(['mcp-hub.models.orders.fields.limit' => 'string',
            'mcp-hub.models.orders.filters.limit' => ['999999999999999999']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(422);
        config(['mcp-hub.models' => config('mcp-hub-test.models'),
            'mcp-hub.models.orders.fields.cursor' => 'string', 'mcp-hub.models.orders.lookup' => 'cursor']);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->getJson('/api/ai/v1/capabilities')->assertStatus(422);
    }

    public function test_plain_http_hub_is_refused_outside_local_environments(): void
    {
        $this->app['env'] = 'production';
        $history = [];
        $client = $this->clientWithResponses([], $history);
        $this->expectExceptionMessage('MCP Hub connection is not configured.');
        $client->rpc('tools/list');
    }

    private function clientWithResponses(array $responses, array &$history): HubClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new HubClient(app(Connection::class), new Client(['handler' => $stack]));
    }

    private function read(int $id, string $path = 'list', array $override = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->withHeaders(['X-Hub-Assertion' => $this->assertion($id, $override)])
            ->getJson('/api/ai/v1/orders/'.$path);
    }

    private function assertion(int $id, array $override = []): string
    {
        $claims = array_replace(['app_key' => 'your_app', 'user_ref' => 'demo_user_'.$id, 'tenant_id' => 'demo_account_'.$id,
            'aud' => 'http://source.test', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16))], $override);
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $encode = function ($value) {
            return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        };
        return $encode($json).'.'.$encode(hash_hmac('sha256', $json, $this->secret, true));
    }
}
