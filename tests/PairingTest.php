<?php

namespace McpHub\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use McpHub\Laravel\Connection;
use McpHub\Laravel\HubTransport;
use McpHub\Laravel\Pairing;
use RuntimeException;

class PairingTest extends TestCase
{
    private $originalStorage;
    private $id = '11111111-1111-4111-8111-111111111111';
    private $connector = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($this->originalStorage.'/framework/testing/pairing-'.bin2hex(random_bytes(8)));
        config(['mcp-hub.enabled' => null, 'mcp-hub.app_key' => null, 'mcp-hub.environment' => null,
            'mcp-hub.url' => null, 'mcp-hub.client_key' => null, 'mcp-hub.app_token' => null,
            'mcp-hub.delegation_secret' => null, 'mcp-hub.source_url' => 'https://source.test']);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        parent::tearDown();
    }

    public function test_proof_is_persisted_before_claim_and_secrets_before_complete(): void
    {
        $history = [];
        $connection = app(Connection::class);
        $pairing = $this->client([
            function ($request) use ($connection) {
                $sent = json_decode((string) $request->getBody(), true);
                $this->assertSame($connection->stored()['pairing']['proof'], $sent['proof']);
                $this->assertFalse($request->hasHeader('Authorization'));
                return $this->claimResponse();
            },
            function () use ($connection) {
                $this->assertSame('dummy-client-key-'.str_repeat('c', 32), $connection->stored()['client_key']);
                $this->assertSame('configured', $connection->stored()['pairing']['status']);
                return $this->response('active');
            },
        ], $history);
        $result = $pairing->connect('https://hub.test/mcp', $this->code());
        $this->assertSame(['status' => 'active', 'enrollment_id' => $this->id], $result);
        $this->assertTrue($connection->get('enabled'));
        $this->assertSame('your_app', $connection->get('app_key'));
        $this->assertCount(2, $history);
        $encrypted = file_get_contents(storage_path('app/mcp-hub/connection.enc'));
        $this->assertStringNotContainsString($connection->stored()['pairing']['proof'], $encrypted);
        $this->assertStringNotContainsString($connection->stored()['client_key'], $encrypted);
        $this->assertArrayNotHasKey('proof', $pairing->status());
    }

    public function test_resume_after_failed_completion_does_not_reclaim_or_rotate(): void
    {
        $history = [];
        $pairing = $this->client([$this->claimResponse(), new Response(503)], $history);
        try {
            $pairing->connect('https://hub.test', $this->code());
            $this->fail('Completion should fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('MCP Hub request failed.', $error->getMessage());
        }
        $before = app(Connection::class)->stored();
        $resumedHistory = [];
        $resumed = $this->client([$this->response('claimed'), $this->response('active')], $resumedHistory)->resume();
        $this->assertSame('active', $resumed['status']);
        $after = app(Connection::class)->stored();
        $this->assertSame($before['client_key'], $after['client_key']);
        $this->assertSame($before['pairing']['proof'], $after['pairing']['proof']);
        foreach ($resumedHistory as $request) {
            $this->assertStringNotContainsString('/claim', $request['request']->getUri()->getPath());
        }
    }

    public function test_mismatched_source_or_hub_does_not_save_credentials(): void
    {
        $history = [];
        $claim = $this->bundle();
        $claim['source_url'] = 'https://other.test';
        $pairing = $this->client([$this->claimResponse($claim)], $history);
        $this->expectException(RuntimeException::class);
        try {
            $pairing->connect('https://hub.test', $this->code());
        } finally {
            $this->assertArrayNotHasKey('client_key', app(Connection::class)->stored());
            $this->assertCount(1, $history);
        }
    }

    public function test_pairing_dry_run_has_no_file_or_network_side_effects(): void
    {
        $history = [];
        $result = $this->client([], $history)->connect('https://hub.test', $this->code(), true);
        $this->assertFalse($result['changed']);
        $this->assertSame([], app(Connection::class)->stored());
        $this->assertSame([], $history);
    }

    public function test_completed_pairing_resumes_without_reissuing_credentials_and_sync_is_scoped(): void
    {
        $history = [];
        $pairing = $this->client([$this->claimResponse(), $this->response('active'),
            $this->response('active'), $this->response('active')], $history);
        $pairing->connect('https://hub.test', $this->code());
        $this->assertSame('active', $pairing->resume()['status']);
        $this->assertSame('active', $pairing->sync()['status']);
        $this->assertCount(4, $history);
        $this->assertStringContainsString('/sync', $history[3]['request']->getUri()->getPath());
        $this->assertFalse($history[3]['request']->hasHeader('Authorization'));
        $this->assertSame(['installation_id', 'attempt_id', 'proof'],
            array_keys(json_decode((string) $history[3]['request']->getBody(), true)));
    }

    public function test_explicit_disable_cannot_be_overridden_by_stored_configuration(): void
    {
        $connection = app(Connection::class);
        $connection->update(['url' => 'https://hub.test/mcp', 'enabled' => true]);
        config(['mcp-hub.enabled' => false]);
        $this->assertFalse($connection->get('enabled'));
        config(['mcp-hub.enabled' => true]);
        $connection->update(['enabled' => false]);
        $this->assertFalse($connection->get('enabled'));
    }

    public function test_partial_connection_updates_preserve_credentials(): void
    {
        $connection = app(Connection::class);
        $connection->save(['client_key' => 'dummy-preserved-key', 'app_key' => 'your_app']);
        $connection->update(['enabled' => false]);
        $this->assertSame('dummy-preserved-key', $connection->stored()['client_key']);
        $this->assertSame('your_app', $connection->stored()['app_key']);
    }

    public function test_replacement_requires_explicit_choice_and_preserves_installation_binding(): void
    {
        $history = [];
        $this->client([$this->claimResponse(), $this->response('active')], $history)->connect('https://hub.test', $this->code());
        $old = app(Connection::class)->stored();
        $this->id = '33333333-3333-4333-8333-333333333333';
        $replacement = $this->bundle();
        $replacement['client_key'] = 'replacement-'.str_repeat('n', 43);
        $replacement['app_token'] = str_repeat('n', 43);
        $replacement['delegation_secret'] = str_repeat('s', 43);
        $requests = [];
        $pairing = $this->client([$this->claimResponse($replacement), $this->response('active')], $requests);
        try {
            $pairing->connect('https://hub.test', $this->code());
            $this->fail('Replacement without an explicit flag was accepted');
        } catch (RuntimeException $error) {
            $this->assertSame([], $requests);
            $this->assertSame($old, app(Connection::class)->stored());
        }
        $this->assertSame('active', $pairing->connect('https://hub.test', $this->code(), false, true)['status']);
        $next = app(Connection::class)->stored();
        $this->assertSame($old['pairing']['installation_id'], $next['pairing']['installation_id']);
        $this->assertNotSame($old['pairing']['proof'], $next['pairing']['proof']);
        $this->assertSame($replacement['client_key'], $next['client_key']);
        foreach (['app_key', 'environment', 'url', 'source_url', 'connector_id'] as $key) {
            $this->assertSame($old[$key], $next[$key]);
        }
    }

    public function test_offline_disconnect_closes_locally_before_network_and_can_retry_revocation(): void
    {
        $history = [];
        $this->client([$this->claimResponse(), $this->response('active')], $history)->connect('https://hub.test', $this->code());
        $before = app(Connection::class)->stored();
        $requests = [];
        $pairing = $this->client([function () {
            $this->assertFalse(app(Connection::class)->get('enabled'));
            return new Response(503);
        }], $requests);
        $this->assertSame('revocation_pending', $pairing->disconnect()['status']);
        $this->assertFalse(app(Connection::class)->get('enabled'));
        $this->assertSame($before['pairing']['proof'], app(Connection::class)->stored()['pairing']['proof']);
        $this->assertFalse($requests[0]['request']->hasHeader('Authorization'));
        $retry = [];
        $this->assertSame('revoked', $this->client([new Response(410)], $retry)->disconnect()['status']);
        $this->assertFalse(app(Connection::class)->get('enabled'));
        $this->assertArrayNotHasKey('proof', $pairing->status());
    }

    private function client(array $responses, array &$history): Pairing
    {
        $stack = HandlerStack::create(function ($request, $options) use (&$responses) {
            $response = array_shift($responses);
            $response = is_callable($response) ? $response($request, $options) : $response;
            return (new MockHandler([$response]))($request, $options);
        });
        $stack->push(Middleware::history($history));
        $connection = app(Connection::class);
        return new Pairing($connection, new HubTransport($connection, new Client(['handler' => $stack])));
    }

    private function response(string $status, array $extra = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true, 'data' => [
            'id' => $this->id, 'connector_id' => $this->connector, 'source_url' => 'https://source.test', 'status' => $status,
        ] + $extra]));
    }

    private function claimResponse(array $bundle = []): Response
    {
        return $this->response('claimed', ['connection' => $bundle ?: $this->bundle()]);
    }

    private function bundle(): array
    {
        return ['app_key' => 'your_app', 'environment' => 'dev', 'url' => 'https://hub.test/mcp',
            'source_url' => 'https://source.test', 'connector_id' => $this->connector,
            'app_token' => str_repeat('a', 43), 'delegation_secret' => str_repeat('d', 43),
            'client_key' => 'dummy-client-key-'.str_repeat('c', 32)];
    }

    private function code(): string
    {
        return 'enr_'.$this->id.'_'.str_repeat('x', 32);
    }
}
