<?php

namespace McpHub\Laravel\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use McpHub\Laravel\Contracts\ActorResolver as ActorResolverContract;
use McpHub\Laravel\Security\Actor;
use RuntimeException;

final class ActorResolver implements ActorResolverContract
{
    public function forUser(Authenticatable $user): Actor
    {
        $current = User::query()->find($user->getAuthIdentifier());
        if (! $current || ! $current->active) {
            throw new RuntimeException('Account is not active.');
        }
        return new Actor($current, 'demo_user_'.$current->getKey(), 'demo_account_'.$current->getKey());
    }

    public function resolve(string $userReference, string $tenant): Actor
    {
        if (! preg_match('/^demo_user_([1-9][0-9]*)$/D', $userReference, $match)) {
            throw new RuntimeException('Unknown actor.');
        }
        $actor = $this->forUser(User::query()->findOrFail($match[1]));
        if ($actor->tenant !== $tenant) {
            throw new RuntimeException('Tenant is not permitted.');
        }
        return $actor;
    }
}
