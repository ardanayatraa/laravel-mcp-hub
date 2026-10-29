<?php

namespace McpHub\Laravel\Contracts;

use Illuminate\Database\Eloquent\Model;
use McpHub\Laravel\Security\Actor;

interface WritePolicy extends ResourcePolicy
{
    /** Validate business rules and return the exact values the user will review. */
    public function validate(Actor $actor, string $resource, string $operation, array $input, ?Model $record): array;

    /** Server-owned ownership/tenant/default attributes; never taken from AI input. */
    public function createAttributes(Actor $actor, string $resource): array;
}
