<?php

namespace App\Mcp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use McpHub\Laravel\Contracts\WritePolicy;
use McpHub\Laravel\Security\Actor;

final class FixtureOrdersPolicy implements WritePolicy
{
    public function allows(Actor $actor, string $resource, string $action): bool
    {
        return $resource === 'orders' && $actor->user->active
            && in_array($action, ['list', 'get', 'count', 'export', 'create', 'update', 'delete'], true);
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
        return $operation === 'create' ? $input + ['status' => 'pending', 'total' => 0] : $input;
    }

    public function createAttributes(Actor $actor, string $resource): array
    {
        return ['user_id' => $actor->user->getAuthIdentifier(), 'private_note' => 'server-owned'];
    }
}
