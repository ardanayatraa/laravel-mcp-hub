<?php

namespace McpHub\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use McpHub\Laravel\Contracts\ResourcePolicy;
use McpHub\Laravel\Security\Actor;

final class ExportPolicy implements ResourcePolicy
{
    public function allows(Actor $actor, string $resource, string $action): bool
    {
        return $actor->user->active && $resource === 'orders'
            && in_array($action, ['list', 'get', 'count', 'export'], true);
    }

    public function scope(Builder $query, Actor $actor): Builder
    {
        return $query->where('user_id', $actor->user->getAuthIdentifier());
    }
}
