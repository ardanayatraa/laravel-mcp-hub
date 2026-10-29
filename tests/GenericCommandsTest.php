<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\Commands\Commands;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Tests\Fixtures\ActorResolver;
use McpHub\Laravel\Tests\Fixtures\GenericOrderCommands;
use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\OrderWritePolicy;
use McpHub\Laravel\Tests\Fixtures\User;

class GenericCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../database/migrations/2026_10_29_000001_create_mcp_hub_commands_table.php';
        (new \CreateMcpHubCommandsTable())->up();
        config(['mcp-hub.enabled' => true, 'mcp-hub.commands.enabled' => true, 'mcp-hub.app_key' => 'your_app',
            'mcp-hub.models.orders.policy' => OrderWritePolicy::class,
            'mcp-hub.models.orders.writable_fields' => ['title', 'status', 'total']]);
        $fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'status' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['paid', 'pending']],
            'total' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10000]];
        foreach (['create', 'update', 'delete'] as $operation) {
            $properties = $operation === 'delete' ? [] : $fields;
            if ($operation !== 'create') {
                $properties['ref'] = ['type' => 'string', 'maxLength' => 1024];
            }
            config(['mcp-hub.models.orders.commands.'.$operation => ['handler' => GenericOrderCommands::class,
                'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => $properties, 'required' => $operation === 'create' ? ['title'] : ['ref']]]]);
        }
        foreach ([1, 2] as $id) {
            User::query()->create(['id' => $id, 'name' => 'Dummy '.$id, 'email' => $id.'@example.test', 'password' => 'unused']);
            Order::query()->create(['user_id' => $id, 'title' => 'Order '.$id, 'status' => 'paid',
                'total' => $id * 100, 'private_note' => 'private-canary-'.$id]);
        }
    }

    public function test_reusable_crud_previews_canonical_values_and_commits_once(): void
    {
        $commands = app(Commands::class);
        $actor = $this->actor();
        $receipt = $commands->prepare($actor, 'orders', 'create', ['title' => '  New order  ']);
        $id = $receipt['proposal_ref'];
        $this->assertSame(['title' => 'New order', 'status' => 'pending', 'total' => 0], $commands->preview($actor, $id)['input']);
        $this->assertSame(2, Order::query()->count());
        $commands->confirm($actor, $id, 'approve');
        $commands->confirm($actor, $id, 'approve');
        $this->assertSame(3, Order::query()->count());
        $record = Order::query()->orderByDesc('id')->first();
        $this->assertSame(1, (int) $record->user_id);
        $update = $commands->prepare($actor, 'orders', 'update', ['ref' => $this->recordRef(1, $record->id), 'title' => 'Changed']);
        $commands->confirm($actor, $update['proposal_ref'], 'approve');
        $this->assertSame('Changed', $record->fresh()->title);
        $delete = $commands->prepare($actor, 'orders', 'delete', ['ref' => $this->recordRef(1, $record->id)]);
        $commands->confirm($actor, $delete['proposal_ref'], 'approve');
        $this->assertSame(2, Order::query()->count());
    }

    public function test_record_scope_and_version_are_rechecked(): void
    {
        $commands = app(Commands::class);
        $actor = $this->actor();
        $receipt = $commands->prepare($actor, 'orders', 'update', ['ref' => $this->recordRef(1, 1), 'title' => 'Proposed']);
        Order::query()->whereKey(1)->update(['title' => 'Concurrent change']);
        try {
            $commands->confirm($actor, $receipt['proposal_ref'], 'approve');
            $this->fail('Concurrent update was accepted');
        } catch (CommandException $error) {
            $this->assertSame('Concurrent change', Order::query()->findOrFail(1)->title);
        }
        $this->expectException(CommandException::class);
        $commands->prepare($actor, 'orders', 'delete', ['ref' => $this->recordRef(1, 2)]);
    }

    public function test_transferred_record_cannot_be_committed(): void
    {
        $commands = app(Commands::class);
        $receipt = $commands->prepare($this->actor(), 'orders', 'delete', ['ref' => $this->recordRef(1, 1)]);
        Order::query()->whereKey(1)->update(['user_id' => 2]);
        $this->expectException(CommandException::class);
        $commands->confirm($this->actor(), $receipt['proposal_ref'], 'approve');
    }

    public function test_changed_server_defaults_require_a_new_proposal(): void
    {
        $commands = app(Commands::class);
        $receipt = $commands->prepare($this->actor(), 'orders', 'create', ['title' => 'New']);
        config(['mcp-hub-test.private_default' => 'changed']);
        try {
            $commands->confirm($this->actor(), $receipt['proposal_ref'], 'approve');
            $this->fail('Changed defaults were accepted');
        } catch (CommandException $error) {
            $this->assertSame(2, Order::query()->count());
            $this->assertSame('pending', $commands->status($this->actor(), $receipt['proposal_ref'])['status']);
        }
    }

    public function test_policy_cannot_add_unreviewed_business_input(): void
    {
        config(['mcp-hub-test.extra_field' => true]);
        $this->expectException(CommandException::class);
        app(Commands::class)->prepare($this->actor(), 'orders', 'create', ['title' => 'New']);
    }

    public function test_created_record_must_belong_to_the_approved_scope(): void
    {
        config(['mcp-hub-test.owner' => 2]);
        $commands = app(Commands::class);
        $receipt = $commands->prepare($this->actor(), 'orders', 'create', ['title' => 'New']);
        try {
            $commands->confirm($this->actor(), $receipt['proposal_ref'], 'approve');
            $this->fail('Out-of-scope creation was accepted');
        } catch (CommandException $error) {
            $this->assertSame(2, Order::query()->count());
        }
    }

    public function test_observer_cannot_change_the_approved_values(): void
    {
        $commands = app(Commands::class);
        $receipt = $commands->prepare($this->actor(), 'orders', 'update', ['ref' => $this->recordRef(1, 1), 'title' => 'Approved']);
        Order::saving(function ($record) { $record->title = 'Unexpected'; });
        try {
            $commands->confirm($this->actor(), $receipt['proposal_ref'], 'approve');
            $this->fail('Unexpected observer values were accepted');
        } catch (CommandException $error) {
            $this->assertSame('Order 1', Order::query()->findOrFail(1)->title);
        } finally {
            Order::flushEventListeners();
        }
    }

    private function actor()
    {
        return app(ActorResolver::class)->forUser(User::query()->findOrFail(1));
    }
}
