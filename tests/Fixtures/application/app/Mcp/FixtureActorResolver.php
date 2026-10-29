<?php

namespace App\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Security\Actor;
use RuntimeException;

final class FixtureActorResolver implements ActorResolver
{
    public function forUser(Authenticatable $user): Actor
    {
        $current = FixtureUser::query()->find($user->getAuthIdentifier());
        if (! $current || ! $current->active) {
            throw new RuntimeException('Account unavailable.');
        }
        return new Actor($current, 'fixture_actor_'.$current->id, 'fixture_tenant_'.$current->id);
    }

    public function resolve(string $reference, string $tenant): Actor
    {
        if (! preg_match('/^fixture_actor_([1-9][0-9]*)$/D', $reference, $match)) {
            throw new RuntimeException('Account unavailable.');
        }
        $actor = $this->forUser(FixtureUser::query()->findOrFail($match[1]));
        if ($actor->tenant !== $tenant) {
            throw new RuntimeException('Account unavailable.');
        }
        return $actor;
    }
}
