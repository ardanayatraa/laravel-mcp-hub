<?php

namespace McpHub\Laravel\Tests\Fixtures;

use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Commands\PreparedCommand;
use McpHub\Laravel\Contracts\CommandHandler;
use McpHub\Laravel\Security\Actor;
use RuntimeException;

final class OrderCommands implements CommandHandler
{
    public function prepare(Actor $actor, string $operation, array $input): PreparedCommand
    {
        $record = $operation === 'create' ? null : $this->record($actor, $input['ref']);
        return new PreparedCommand($input, ['version' => $record ? $this->version($record) : null],
            $record ? ['title' => $record->title, 'status' => $record->status, 'total' => (float) $record->total] : []);
    }

    public function commit(Actor $actor, string $operation, array $input, array $prepared): void
    {
        if ($operation === 'create') {
            Order::query()->create(['user_id' => $actor->user->getAuthIdentifier(), 'title' => $input['title'],
                'status' => $input['status'] ?? 'pending', 'total' => $input['total'] ?? 0,
                'private_note' => 'server-owned']);
        } else {
            $record = $this->record($actor, $input['ref']);
            if (! hash_equals($prepared['version'], $this->version($record))) {
                throw new CommandException('Record changed.');
            }
            if ($operation === 'delete') {
                $record->delete();
            } else {
                $record->fill(array_intersect_key($input, array_flip(['title', 'status', 'total'])))->save();
            }
        }
        if (config('mcp-hub-test.fail_commit')) {
            throw new RuntimeException('private-handler-canary');
        }
    }

    private function record(Actor $actor, string $ref): Order
    {
        $key = app(\McpHub\Laravel\Security\RecordReferences::class)->decode('orders', $actor, $ref);
        $record = (new CommandPolicy())->scope(Order::query(), $actor)->whereKey($key)->lockForUpdate()->first();
        if (! $record) {
            throw new CommandException('Record unavailable.', 403);
        }
        return $record;
    }

    private function version(Order $record): string
    {
        return hash('sha256', json_encode($record->getAttributes()));
    }
}
