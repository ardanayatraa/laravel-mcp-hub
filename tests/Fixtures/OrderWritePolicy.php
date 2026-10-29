<?php

namespace McpHub\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use McpHub\Laravel\Contracts\WritePolicy;
use McpHub\Laravel\Security\Actor;

final class OrderWritePolicy implements WritePolicy
{
    public function allows(Actor $actor, string $resource, string $action): bool
    {
        return $actor->user->active && $resource === 'orders';
    }

    public function scope(Builder $query, Actor $actor): Builder
    {
        return $query->where('user_id', $actor->user->getAuthIdentifier());
    }

    public function validate(Actor $actor, string $resource, string $operation, array $input, ?Model $record): array
    {
        if (isset($input['title'])) {
            $input['title'] = trim($input['title']);
        }
        if ($operation === 'create') {
            $input += ['status' => 'pending', 'total' => 0];
        }
        if (config('mcp-hub-test.extra_field')) {
            $input['user_id'] = 2;
        }
        return $input;
    }

    public function createAttributes(Actor $actor, string $resource): array
    {
        return ['user_id' => config('mcp-hub-test.owner', $actor->user->getAuthIdentifier()),
            'private_note' => config('mcp-hub-test.private_default', 'server-owned')];
    }
}
