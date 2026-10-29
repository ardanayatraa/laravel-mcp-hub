<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'mcp-hub:install {--dry-run} {--migrate : Run only package metadata migrations} {--force : Allow metadata migrations in production}';
    protected $description = 'Publish missing MCP Hub configuration without replacing application settings';

    public function handle(): int
    {
        if ($this->option('migrate') && ! $this->option('dry-run') && app()->environment('production') && ! $this->option('force')) {
            $this->error('Production metadata migration requires --force after reviewing your database backup.');
            return 1;
        }
        $target = config_path('mcp-hub.php');
        if (is_file($target)) {
            $this->info('Configuration already exists; no changes made.');
        } elseif ($this->option('dry-run')) {
            $this->info('Would create config/mcp-hub.php with no registered models.');
        } elseif (! copy(dirname(__DIR__, 2).'/config/mcp-hub.php', $target)) {
            $this->error('Configuration could not be written.');
            return 1;
        } else {
            $this->info('Configuration installed. No models or permissions were enabled.');
        }
        if ($this->option('migrate')) {
            if ($this->option('dry-run')) {
                $this->info('Would run package metadata migrations only (commands, exports and replay protection).');
                return 0;
            }
            try {
                // Run only these files. The application's migrate command may restore
                // a business schema dump before applying --path, or be overridden.
                $repository = $this->laravel->make('migration.repository');
                if (! $repository->repositoryExists()) {
                    $repository->createRepository();
                }
                $this->laravel->make('migrator')->run([dirname(__DIR__, 2).'/database/migrations'], ['pretend' => false]);
                $this->info('Package metadata migrations are current. Application migrations were not run.');
            } catch (Throwable $error) {
                $this->error('Package metadata migration failed. Check database access and migration state.');
                return 1;
            }
        }
        return 0;
    }
}
