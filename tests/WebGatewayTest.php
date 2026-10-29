<?php

namespace McpHub\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Gate;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Gateway;
use McpHub\Laravel\HubTransport;
use McpHub\Laravel\Security\Assertions;
use McpHub\Laravel\Tests\Fixtures\User;

class WebGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp-hub.enabled' => true, 'mcp-hub.app_key' => 'your_app', 'mcp-hub.url' => 'https://hub.test/mcp',
            'mcp-hub.client_key' => 'private-browser-key-canary', 'mcp-hub.source_url' => 'https://source.test',
            'mcp-hub.delegation_secret' => str_repeat('d', 32)]);
        User::query()->create(['id' => 1, 'name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'unused']);
    }

    public function test_chat_is_closed_until_the_host_explicitly_authorizes_it(): void
    {
        $this->get('/mcp-hub/chat')->assertStatus(403);
        $this->actingAs(User::query()->findOrFail(1));
        $this->get('/mcp-hub/chat')->assertStatus(403);
        $this->getJson('/mcp-hub/models')->assertStatus(403);
    }

    public function test_authorized_page_is_translated_and_never_contains_server_credentials(): void
    {
        $this->allow();
        $this->app->setLocale('id');
        $response = $this->get('/mcp-hub/chat')->assertStatus(200)->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('Asisten aplikasi', $response->getContent());
        $this->assertStringNotContainsString('private-browser-key-canary', $response->getContent());
        $this->assertStringNotContainsString(str_repeat('d', 32), $response->getContent());
    }

    public function test_chat_assets_are_fixed_public_files_and_cannot_read_arbitrary_paths(): void
    {
        $response = $this->get('/mcp-hub/assets/chat.js')->assertStatus(200)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('private-browser-key-canary', $response->getContent());
        $this->get('/mcp-hub/assets/auth.json')->assertStatus(404);
    }

    public function test_session_and_csrf_are_required_before_the_sdk_is_called(): void
    {
        $this->allow();
        $this->postJson('/mcp-hub/chat', ['message' => 'Show orders'])->assertStatus(419);
    }

    public function test_browser_turn_uses_the_server_resolved_actor_and_blocks_spoofed_arguments(): void
    {
        $this->allow();
        $history = [];
        $this->bindSdk([new Response(200, [], json_encode(['success' => true,
            'data' => ['text' => 'Safe reply', 'proposals' => []]]))], $history);
        $this->withSession(['_token' => 'dummy-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'dummy-csrf']);
        $response = $this->postJson('/mcp-hub/chat', ['message' => 'Show orders'])->assertStatus(200);
        $this->assertSame('Safe reply', $response->json()['data']['text']);
        $this->postJson('/mcp-hub/chat', ['message' => 'Show orders', 'user_id' => 2])->assertStatus(422);
        $this->postJson('/mcp-hub/chat', ['message' => 'canary@example.test'])->assertStatus(403);
        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('private-browser-key-canary', $response->getContent());
    }

    public function test_upstream_errors_expose_only_a_reviewed_error_code(): void
    {
        $this->allow();
        $history = [];
        $this->bindSdk([new Response(403, [], json_encode(['error' => [
            'code' => 'MODEL_NOT_ALLOWED', 'message' => 'private-upstream-message-canary',
        ]]))], $history);
        $this->withSession(['_token' => 'dummy-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'dummy-csrf']);
        $response = $this->postJson('/mcp-hub/chat', ['message' => 'Show orders'])->assertStatus(403);
        $this->assertSame('MODEL_NOT_ALLOWED', $response->json()['error']['code']);
        $this->assertStringNotContainsString('private-upstream-message-canary', $response->getContent());
    }

    private function allow(): void
    {
        Gate::define('mcp-hub.chat', function ($user) { return $user->getAuthIdentifier() === 1; });
        $this->actingAs(User::query()->findOrFail(1));
    }

    private function bindSdk(array $responses, array &$history): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $sdk = new Gateway(new HubTransport(app(Connection::class), new Client(['handler' => $stack])),
            app(ActorResolver::class), app(Assertions::class));
        $this->app->instance(Gateway::class, $sdk);
    }
}
