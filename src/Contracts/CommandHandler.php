<?php

namespace McpHub\Laravel\Contracts;

use McpHub\Laravel\Security\Actor;
use McpHub\Laravel\Commands\PreparedCommand;

interface CommandHandler
{
    /** Validate business rules and record scope without modifying business data.
     * Return server-owned prepared state, including any record version to recheck.
     */
    public function prepare(Actor $actor, string $operation, array $input): PreparedCommand;

    /** Recheck record scope/version and execute on the application's default DB.
     * Runs inside the same transaction as the proposal receipt. Do not perform
     * external side effects here; use a transactional application outbox.
     */
    public function commit(Actor $actor, string $operation, array $input, array $state): void;
}
