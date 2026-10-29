<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Pairing;
use Throwable;

final class ConnectCommand extends Command
{
    protected $signature = 'mcp-hub:connect {--import-env : Save an already provisioned connection} {--hub= : Hub URL} {--code= : One-use enrollment code} {--resume : Resume the saved enrollment} {--replace : Install an administrator-issued replacement for this connection} {--dry-run}';
    protected $description = 'Pair once with the Hub or import an existing connection';

    public function handle(Connection $connection, Pairing $pairing): int
    {
        if ($this->option('resume') || $this->option('hub')) {
            try {
                if ($this->option('resume') && $this->option('dry-run')) {
                    $this->line('Would resume the saved enrollment; no changes made.');
                    return 0;
                }
                $code = (string) ($this->option('code') ?: ($this->option('resume') ? '' : $this->secret('One-use pairing code')));
                $status = $this->option('resume') ? $pairing->resume()
                    : $pairing->connect((string) $this->option('hub'), $code, (bool) $this->option('dry-run'), (bool) $this->option('replace'));
                $this->info($status['status'] === 'active' ? 'Hub connection is active.'
                    : 'Pairing inputs are valid; no changes made.');
                return 0;
            } catch (Throwable $error) {
                $this->error('Pairing did not complete. Credentials were not printed. Check configuration, then use --resume.');
                return 1;
            }
        }
        if (! $this->option('import-env')) {
            $this->error('Use --hub=<Hub URL> to pair, or --import-env for existing credentials.');
            return 2;
        }
        try {
            $keys = ['app_key', 'environment', 'url', 'client_key', 'source_url', 'app_token', 'delegation_secret'];
            $values = [];
            foreach ($keys as $key) {
                $value = $connection->get($key);
                if (! is_string($value) || trim($value) === '') {
                    $this->error('Missing connection setting: '.$key);
                    return 2;
                }
                $values[$key] = $value;
            }
            foreach (['app_token', 'delegation_secret'] as $key) {
                if (strlen($values[$key]) < 32) {
                    $this->error('Invalid connection setting: '.$key);
                    return 2;
                }
            }
            if (! $this->option('dry-run')) {
                $connection->save($values);
            }
            $this->info($this->option('dry-run') ? 'Connection settings are present; no changes made.'
                : 'Connection settings saved encrypted. Run mcp-hub:doctor --online to verify connectivity.');
            return 0;
        } catch (Throwable $error) {
            $this->error('Connection could not be imported. Credentials were not printed.');
            return 1;
        }
    }
}
