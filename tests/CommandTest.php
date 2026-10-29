<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Tests\Fixtures\CommandPolicy;
use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\OrderCommands;
use McpHub\Laravel\Tests\Fixtures\User;

class CommandTest extends TestCase
{
    private $token = 'dummy-source-token-at-least-32-characters';
    private $secret = 'dummy-delegation-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../database/migrations/2026_10_29_000001_create_mcp_hub_commands_table.php';
        (new \CreateMcpHubCommandsTable())->up();
        config(['mcp-hub.enabled' => true, 'mcp-hub.commands.enabled' => true, 'mcp-hub.app_key' => 'your_app',
            'mcp-hub.source_url' => 'http://source.test', 'mcp-hub.app_token' => $this->token,
            'mcp-hub.delegation_secret' => $this->secret, 'mcp-hub.require_https' => false,
            'mcp-hub.models.orders.policy' => CommandPolicy::class,
            'mcp-hub.models.orders.writable_fields' => ['title', 'status', 'total']]);
        $fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'status' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['paid', 'pending']],
            'total' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10000]];
        foreach (['create', 'update', 'delete'] as $operation) {
            $properties = $operation === 'delete' ? [] : $fields;
            if ($operation !== 'create') {
                $properties['ref'] = ['type' => 'string', 'maxLength' => 1024];
            }
            config(['mcp-hub.models.orders.commands.'.$operation => [
                'handler' => OrderCommands::class, 'input_schema' => ['type' => 'object',
                    'additionalProperties' => false, 'properties' => $properties,
                    'required' => $operation === 'create' ? ['title'] : ['ref']],
            ]]);
        }
        foreach ([1, 2] as $id) {
            User::query()->create(['id' => $id, 'name' => 'Dummy '.$id, 'email' => $id.'@example.test', 'password' => 'unused']);
            Order::query()->create(['user_id' => $id, 'title' => 'Order '.$id, 'status' => 'paid',
                'total' => $id * 100, 'private_note' => 'private-canary-'.$id]);
        }
        $this->withSession(['_token' => 'dummy-browser-csrf-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'dummy-browser-csrf-token']);
    }

    public function test_preparation_is_encrypted_and_does_not_change_records(): void
    {
        $response = $this->prepare(1, 'create', ['title' => 'prepared-value-canary'])->assertStatus(200);
        $receipt = $response->json()['data'][0];
        $this->assertSame('pending', $receipt['status']);
        $this->assertTrue($receipt['requires_confirmation']);
        $this->assertSame(2, Order::query()->count());
        $this->assertStringNotContainsString('prepared-value-canary', $response->getContent());
        $this->assertStringNotContainsString('prepared-value-canary', DB::table('mcp_hub_commands')->first()->payload);
    }

    public function test_create_update_and_delete_require_confirmation_and_commit_once(): void
    {
        foreach ([
            ['create', ['title' => 'Created order'], 3],
            ['update', ['ref' => $this->recordRef(1, 1), 'title' => 'Updated order'], 3],
            ['delete', ['ref' => $this->recordRef(1, 1)], 2],
        ] as $case) {
            list($operation, $input, $expectedCount) = $case;
            $id = $this->proposal(1, $operation, $input);
            $this->actingAs(User::query()->findOrFail(1));
            $receipt = $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200)->json()['data'];
            $this->assertSame('committed', $receipt['status']);
            $this->assertFalse($receipt['requires_confirmation']);
            $this->assertSame($expectedCount, Order::query()->count());
            $again = $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200);
            $this->assertSame($receipt, $again->json()['data']);
            $this->assertSame($expectedCount, Order::query()->count());
        }
    }

    public function test_another_actor_cannot_see_or_confirm_the_proposal(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'User one']);
        $this->headers(2)->getJson('/api/ai/v1/orders/commands/'.$id)->assertStatus(404);
        $this->actingAs(User::query()->findOrFail(2));
        $this->get('/mcp-hub/proposals/'.$id)->assertStatus(404);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(404);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_service_credentials_and_model_arguments_cannot_approve(): void
    {
        $this->prepare(1, 'create', ['title' => 'Injected', 'approved' => true])->assertStatus(422);
        $id = $this->proposal(1, 'create', ['title' => 'Session required']);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(403);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_schema_closes_unknown_fields_types_and_limits(): void
    {
        foreach ([
            ['title' => 'x', 'user_id' => 2], ['title' => 'x', 'role' => 'admin'], ['title' => 'x', 'other' => 'x'],
            ['title' => str_repeat('x', 101)], ['title' => ['nested']], ['status' => 'paid'],
            ['title' => 'x', 'total' => -1], ['title' => 'x', 'total' => '10'], ['title' => 'x', 'status' => 'unknown'],
        ] as $input) {
            $this->prepare(1, 'create', $input)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('mcp_hub_commands')->count());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_idempotency_is_actor_bound_and_rejects_a_different_payload(): void
    {
        $key = 'dummy-idempotency-key-123456';
        $first = $this->prepare(1, 'create', ['title' => 'Once'], $key)->assertStatus(200)->json()['data'][0];
        $same = $this->prepare(1, 'create', ['title' => 'Once'], $key)->assertStatus(200)->json()['data'][0];
        $this->assertSame($first, $same);
        $this->prepare(1, 'create', ['title' => 'Different'], $key)->assertStatus(409);
        $other = $this->prepare(2, 'create', ['title' => 'Once'], $key)->assertStatus(200)->json()['data'][0];
        $this->assertNotSame($first['proposal_ref'], $other['proposal_ref']);
        $this->assertSame(2, DB::table('mcp_hub_commands')->count());
    }

    public function test_record_scope_is_enforced_for_updates_and_deletes(): void
    {
        foreach (['update', 'delete'] as $operation) {
            $this->prepare(1, $operation, ['ref' => $this->recordRef(1, 2)])->assertStatus(403);
        }
        $this->assertSame(0, DB::table('mcp_hub_commands')->count());
    }

    public function test_expired_proposal_cannot_commit(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Expired']);
        DB::table('mcp_hub_commands')->where('id', $id)->update(['expires_at' => time() - 1]);
        $this->headers(1)->getJson('/api/ai/v1/orders/commands/'.$id)->assertStatus(200)
            ->assertJson(['data' => [['status' => 'expired', 'requires_confirmation' => false]]]);
        $this->actingAs(User::query()->findOrFail(1));
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(410);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_revoked_policy_or_changed_configuration_blocks_a_pending_commit(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Revoked']);
        $this->actingAs(User::query()->findOrFail(1));
        config(['mcp-hub-test.deny_write' => true]);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(403);
        config(['mcp-hub-test.deny_write' => false, 'mcp-hub.models.orders.writable_fields' => ['title', 'status', 'total', 'note']]);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_handler_failure_rolls_back_business_write_and_receipt(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Rollback']);
        config(['mcp-hub-test.fail_commit' => true]);
        $this->actingAs(User::query()->findOrFail(1));
        $response = $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(403);
        $this->assertStringNotContainsString('private-handler-canary', $response->getContent());
        $this->assertSame('pending', DB::table('mcp_hub_commands')->where('id', $id)->first()->status);
        $this->assertSame(2, Order::query()->count());
        config(['mcp-hub-test.fail_commit' => false]);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200);
        $this->assertSame(3, Order::query()->count());
    }

    public function test_changed_record_is_rejected_before_commit(): void
    {
        $id = $this->proposal(1, 'update', ['ref' => $this->recordRef(1, 1), 'title' => 'Proposed']);
        Order::query()->whereKey(1)->update(['title' => 'Changed elsewhere']);
        $this->actingAs(User::query()->findOrFail(1));
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame('Changed elsewhere', Order::query()->findOrFail(1)->title);
        $this->assertSame('pending', DB::table('mcp_hub_commands')->where('id', $id)->first()->status);
    }

    public function test_cancellation_prevents_a_later_commit(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Cancelled']);
        $this->actingAs(User::query()->findOrFail(1));
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'cancel'])->assertStatus(200);
        $receipt = $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200)->json()['data'];
        $this->assertSame('cancelled', $receipt['status']);
        $this->assertSame('', DB::table('mcp_hub_commands')->where('id', $id)->first()->payload);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_web_preview_escapes_values_and_blocks_framing(): void
    {
        $id = $this->proposal(1, 'create', ['title' => '<script>alert(1)</script>']);
        $this->actingAs(User::query()->findOrFail(1));
        $response = $this->get('/mcp-hub/proposals/'.$id)->assertStatus(200)->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $this->assertStringContainsString('&lt;script&gt;', $response->getContent());
    }

    public function test_commands_are_disabled_by_default_and_disappear_when_revoked(): void
    {
        $defaults = require __DIR__.'/../config/mcp-hub.php';
        $this->assertFalse($defaults['commands']['enabled']);
        $manifest = app(Registry::class)->manifest(app(Connection::class));
        $this->assertCount(3, $manifest['modules'][0]['write_endpoints']);
        $this->assertSame('proposal', $manifest['modules'][0]['write_endpoints'][0]['workflow']);
        config(['mcp-hub.commands.enabled' => false]);
        $this->prepare(1, 'create', ['title' => 'Disabled'])->assertStatus(403);
        $manifest = app(Registry::class)->manifest(app(Connection::class));
        $this->assertArrayNotHasKey('write_endpoints', $manifest['modules'][0]);
        $this->assertSame(['read'], $manifest['modules'][0]['supported_operations']);
    }

    public function test_terminal_receipt_remains_reconcilable_after_unrelated_grant_changes(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Reconciled']);
        $this->actingAs(User::query()->findOrFail(1));
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200);
        config(['mcp-hub.resource_grants' => ['orders' => ['enabled' => true, 'fields' => ['title', 'status', 'total'],
            'operations' => ['create', 'update']]]]);
        $this->headers(1)->getJson('/api/ai/v1/orders/commands/'.$id)->assertStatus(200)->assertJson(['data' => [['status' => 'committed']]]);
        $this->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(200);
        $this->assertSame(3, Order::query()->count());
    }

    public function test_confirmation_requires_csrf_even_in_a_test_or_exempt_host(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'CSRF boundary']);
        $this->actingAs(User::query()->findOrFail(1));
        $this->withHeaders(['X-CSRF-TOKEN' => ''])->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])
            ->assertStatus(419);
        $this->withHeaders(['X-CSRF-TOKEN' => 'wrong'])->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])
            ->assertStatus(419);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_cross_origin_confirmation_is_denied_with_a_valid_session(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Origin boundary']);
        $this->actingAs(User::query()->findOrFail(1));
        $this->withHeaders(['Origin' => 'https://other.test'])
            ->postJson('/mcp-hub/proposals/'.$id, ['decision' => 'approve'])->assertStatus(403);
        $this->assertSame(2, Order::query()->count());
    }

    private function proposal(int $user, string $operation, array $input): string
    {
        return $this->prepare($user, $operation, $input)->assertStatus(200)->json()['data'][0]['proposal_ref'];
    }

    public function test_command_receipts_are_bound_to_source_and_environment(): void
    {
        $id = $this->proposal(1, 'create', ['title' => 'Bound proposal']);
        $actor = app(\McpHub\Laravel\Contracts\ActorResolver::class)->forUser(User::query()->findOrFail(1));
        foreach (['environment' => 'other_environment', 'source_url' => 'https://other-source.test'] as $key => $value) {
            $original = config('mcp-hub.'.$key);
            config(['mcp-hub.'.$key => $value]);
            try {
                app(\McpHub\Laravel\Commands\Commands::class)->status($actor, $id);
                $this->fail('A different source/environment must not access the receipt.');
            } catch (\McpHub\Laravel\Commands\CommandException $error) {
                $this->assertSame(404, $error->status());
            }
            config(['mcp-hub.'.$key => $original]);
        }
        $this->assertSame('pending', app(\McpHub\Laravel\Commands\Commands::class)->status($actor, $id)['status']);
    }

    private function prepare(int $user, string $operation, array $input, string $key = '')
    {
        return $this->headers($user)->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/ai/v1/orders/commands/'.$operation, $input);
    }

    private function headers(int $user)
    {
        $claims = ['app_key' => 'your_app', 'user_ref' => 'demo_user_'.$user, 'tenant_id' => 'demo_account_'.$user,
            'aud' => 'http://source.test', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(16))];
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        $encode = function ($value) {
            return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        };
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token,
            'X-Hub-Assertion' => $encode($json).'.'.$encode(hash_hmac('sha256', $json, $this->secret, true))]);
    }
}
