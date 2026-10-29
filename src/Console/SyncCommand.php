<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Pairing;
use McpHub\Laravel\Registry;
use Throwable;

final class SyncCommand extends Command
{
    protected $signature = 'mcp-hub:sync {--dry-run}';
    protected $description = 'Validate the current manifest and synchronize through installation-scoped authority';

    public function handle(Connection $connection, Pairing $pairing, Registry $registry): int
    {
        try {
            $registry->manifest($connection);
            if (! $this->option('dry-run')) {
                $pairing->sync();
            }
            $this->info($this->option('dry-run') ? 'Manifest is valid. No network requests or changes made.' : 'Source metadata synchronized.');
            return 0;
        } catch (Throwable $error) {
            $this->error('Synchronization failed. Check connection status, enrollment expiry and application configuration.');
            return 1;
        }
    }
}
