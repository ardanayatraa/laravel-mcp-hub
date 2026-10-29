<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Pairing;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Support\Json;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'mcp-hub:status {--json}';
    protected $description = 'Show local connection metadata without network calls or business reads';

    public function handle(Connection $connection, Pairing $pairing, Registry $registry): int
    {
        try {
            $data = ['laravel' => app()->version(), 'php' => PHP_VERSION, 'enabled' => (bool) $connection->get('enabled'),
                'configured' => (bool) $connection->get('url') && (bool) $connection->get('client_key'),
                'resources' => count($registry->resources()), 'commands_enabled' => (bool) config('mcp-hub.commands.enabled'),
                'replay_metadata' => Schema::hasTable('mcp_hub_nonces'), 'pairing' => $pairing->status()];
            $this->line($this->option('json') ? Json::encode($data) :
                'Connection: '.($data['enabled'] ? 'enabled' : 'closed').'; resources: '.$data['resources'].'; pairing: '.$data['pairing']['status']);
            return 0;
        } catch (Throwable $error) {
            $this->error('Local status could not be checked. Run mcp-hub:doctor.');
            return 1;
        }
    }
}
