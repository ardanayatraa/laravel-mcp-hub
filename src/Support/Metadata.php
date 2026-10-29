<?php

namespace McpHub\Laravel\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class Metadata
{
    public function inspect(): array
    {
        try {
            return [
                'replay_metadata' => Schema::hasColumns('mcp_hub_nonces', ['nonce', 'expires_at']),
                'command_metadata' => ! config('mcp-hub.commands.enabled') || Schema::hasColumns('mcp_hub_commands',
                    ['id', 'actor_key', 'request_key', 'request_digest', 'policy_digest', 'resource', 'operation',
                        'payload', 'status', 'created_at', 'expires_at']),
                'export_metadata' => ! config('mcp-hub.exports.enabled') || Schema::hasColumns('mcp_hub_exports',
                    ['id', 'actor_key', 'request_key', 'request_digest', 'policy_digest', 'resource', 'format',
                        'status', 'payload', 'coverage', 'row_count', 'created_at', 'expires_at']),
            ];
        } catch (Throwable $error) {
            return ['replay_metadata' => false, 'command_metadata' => false, 'export_metadata' => false];
        }
    }
}
