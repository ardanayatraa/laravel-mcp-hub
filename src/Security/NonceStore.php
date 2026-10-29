<?php

namespace McpHub\Laravel\Security;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class NonceStore
{
    public function consume(string $nonce, int $expires): void
    {
        try {
            // The primary key is the authority, shared by every application worker.
            // There is no check-then-insert race or local-cache fallback.
            $ok = DB::table('mcp_hub_nonces')->insert(['nonce' => $nonce, 'expires_at' => $expires]);
        } catch (Throwable $error) {
            throw new RuntimeException('Actor access denied.');
        }
        if (! $ok) {
            throw new RuntimeException('Actor access denied.');
        }
    }
}
