<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use McpHub\Laravel\Security\NonceStore;
use RuntimeException;
use Symfony\Component\Process\Process;

class MetadataTest extends TestCase
{
    public function test_expired_metadata_cleanup_is_registered_and_can_be_disabled(): void
    {
        $scheduleClass = \Illuminate\Console\Scheduling\Schedule::class;
        $this->app->forgetInstance($scheduleClass);
        $events = app($scheduleClass)->events();
        $prune = array_values(array_filter($events, function ($event) {
            return strpos((string) $event->command, 'mcp-hub:prune') !== false;
        }));
        $this->assertCount(1, $prune);
        $this->assertSame('0 * * * *', $prune[0]->expression);
        $this->assertTrue($prune[0]->withoutOverlapping);
        config(['mcp-hub.maintenance.scheduled_prune' => false]);
        $this->app->forgetInstance($scheduleClass);
        $this->assertCount(0, array_filter(app($scheduleClass)->events(), function ($event) {
            return strpos((string) $event->command, 'mcp-hub:prune') !== false;
        }));
    }

    public function test_nonce_survives_cache_clear_and_an_independent_store(): void
    {
        $nonce = hash('sha256', 'dummy-nonce');
        (new NonceStore())->consume($nonce, time() + 120);
        app('cache')->flush();
        $this->expectException(RuntimeException::class);
        (new NonceStore())->consume($nonce, time() + 120);
    }

    public function test_independent_php_processes_accept_one_assertion_once(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-nonce-');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE mcp_hub_nonces (nonce VARCHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL)');
        $pdo = null;
        $autoload = realpath(__DIR__.'/../vendor/autoload.php');
        // The matrix loads a separate Composer fixture, with the current PHP runtime.
        foreach (get_included_files() as $included) {
            if (substr(str_replace('\\', '/', $included), -20) === '/vendor/autoload.php') {
                $autoload = $included;
                break;
            }
        }
        $command = [PHP_BINARY, __DIR__.'/Fixtures/nonce-worker.php', $autoload, $path];
        if (PHP_OS_FAMILY === 'Windows') {
            $probe = new Process([PHP_BINARY, '-r', 'echo extension_loaded("pdo_sqlite") ? "yes" : "no";']);
            $probe->mustRun();
            if ($probe->getOutput() === 'no') {
                array_splice($command, 1, 0, ['-d', 'extension=php_pdo_sqlite.dll']);
            }
        }
        $workers = [new Process($command), new Process($command)];
        try {
            foreach ($workers as $worker) {
                $worker->setTimeout(15);
                $worker->start();
            }
            $accepted = 0;
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $accepted += json_decode($worker->getOutput(), true)['accepted'];
            }
            $this->assertSame(1, $accepted);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            unlink($path);
        }
    }

    public function test_installer_runs_only_package_migrations_and_is_repeatable(): void
    {
        Schema::drop('mcp_hub_nonces');
        $directory = database_path('migrations');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $canary = $directory.'/2026_10_28_000000_mcp_unexpected_business_migration.php';
        $schemaDirectory = database_path('schema');
        if (! is_dir($schemaDirectory)) { mkdir($schemaDirectory, 0700, true); }
        $schemaCanary = $schemaDirectory.'/testing-schema.sql';
        $previousSchema = is_file($schemaCanary) ? file_get_contents($schemaCanary) : null;
        file_put_contents($schemaCanary, 'CREATE TABLE unexpected_business_schema (id INTEGER PRIMARY KEY);');
        file_put_contents($canary, '<?php class McpUnexpectedBusinessMigration extends \\Illuminate\\Database\\Migrations\\Migration {
            public function up() { throw new \\RuntimeException("Application migration must not run"); }
            public function down() {} }');
        try {
            $this->assertSame(0, Artisan::call('mcp-hub:install', ['--dry-run' => true, '--migrate' => true]));
            $this->assertFalse(Schema::hasTable('mcp_hub_nonces'));
            $this->assertSame(0, Artisan::call('mcp-hub:install', ['--migrate' => true]));
            $this->assertTrue(Schema::hasTable('mcp_hub_nonces'));
            $this->assertTrue(Schema::hasTable('mcp_hub_commands'));
            $this->assertFalse(Schema::hasTable('unexpected_business_schema'));
            $this->assertSame(3, DB::table('migrations')->count());
            $this->assertSame(0, Artisan::call('mcp-hub:install', ['--migrate' => true]));
            $this->assertSame(3, DB::table('migrations')->count());
        } finally {
            unlink($canary);
            if ($previousSchema === null) { unlink($schemaCanary); }
            else { file_put_contents($schemaCanary, $previousSchema); }
        }
    }

    public function test_production_install_requires_explicit_migration_flag(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('mcp-hub:install', ['--migrate' => true]));
        $this->assertStringContainsString('--force', Artisan::output());
    }

    public function test_prune_removes_only_expired_metadata(): void
    {
        require_once __DIR__.'/../database/migrations/2026_10_29_000001_create_mcp_hub_commands_table.php';
        (new \CreateMcpHubCommandsTable())->up();
        DB::table('mcp_hub_nonces')->insert([
            ['nonce' => hash('sha256', 'expired'), 'expires_at' => time() - 1],
            ['nonce' => hash('sha256', 'active'), 'expires_at' => time() + 150],
        ]);
        foreach (['pending', 'committed', 'cancelled'] as $status) {
            DB::table('mcp_hub_commands')->insert(['id' => $status, 'actor_key' => 'dummy', 'request_key' => $status,
                'request_digest' => 'dummy', 'policy_digest' => 'dummy', 'resource' => 'orders', 'operation' => 'update',
                'payload' => 'encrypted-canary', 'status' => $status, 'created_at' => time() - 100,
                'expires_at' => $status === 'committed' ? time() - 40 * 86400 : time() - 1]);
        }
        $this->assertSame(0, Artisan::call('mcp-hub:prune'));
        $this->assertSame(1, DB::table('mcp_hub_nonces')->count());
        $pending = DB::table('mcp_hub_commands')->where('id', 'pending')->first();
        $this->assertSame('expired', $pending->status);
        $this->assertSame('', $pending->payload);
        $this->assertSame(2, DB::table('mcp_hub_commands')->count());
        $this->assertSame(1, Artisan::call('mcp-hub:prune', ['--days' => '0']));
    }
}
