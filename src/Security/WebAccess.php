<?php

namespace McpHub\Laravel\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class WebAccess
{
    public function user(string $permission): Authenticatable
    {
        $user = Auth::guard(config('mcp-hub.web.guard'))->user();
        $gate = config('mcp-hub.web.'.$permission);
        if (! $user || ! is_string($gate) || $gate === '' || ! Gate::forUser($user)->allows($gate)) {
            throw new RuntimeException('Access denied.');
        }
        return $user;
    }
}
