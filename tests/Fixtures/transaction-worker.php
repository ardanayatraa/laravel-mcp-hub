<?php

// Independent PHP workers against an explicitly supplied disposable PostgreSQL schema.
// Connection values stay in a mode-0600 file and are never command-line arguments/logs.
require $argv[1];
set_error_handler(function ($severity, $message, $file, $line) {
    throw new \ErrorException('Fixture operation failed.', 0, $severity, $file, $line);
});
$settings = json_decode(file_get_contents($argv[2]), true);
if (! is_array($settings) || ! in_array($settings['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    || ! preg_match('/^[a-z][a-z0-9_]*_test$/D', $settings['database'] ?? '')
    || ! preg_match('/^mcp_fixture_[a-f0-9]{24}$/D', $settings['schema'] ?? '')) {
    exit(2);
}

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use McpHub\Laravel\Commands\Commands;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Tests\Fixtures\ActorResolver;
use McpHub\Laravel\Tests\Fixtures\GenericOrderCommands;
use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\OrderWritePolicy;
use McpHub\Laravel\Tests\Fixtures\User;

try {
    $pdo = new PDO('pgsql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'],
        $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $action = $argv[3];
    if ($action === 'cleanup') {
        $pdo->exec('DROP SCHEMA IF EXISTS "'.$settings['schema'].'" CASCADE');
        echo json_encode(['cleaned' => true]);
        exit;
    }
    if ($action === 'init') {
        $pdo->exec('CREATE SCHEMA "'.$settings['schema'].'"');
    }
    $app = new \Illuminate\Foundation\Application(dirname($argv[2]));
    $app->useStoragePath(dirname($argv[2]).'/storage');
    $app->instance('env', 'testing');
    $config = new Repository(['database' => ['default' => 'testing'], 'mcp-hub' => require __DIR__.'/../../config/mcp-hub.php']);
    $app->instance('config', $config);
    $app->alias('config', \Illuminate\Contracts\Config\Repository::class);
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $encrypter = new \Illuminate\Encryption\Encrypter(str_repeat('t', 32), 'AES-256-CBC');
    $app->instance(\Illuminate\Contracts\Encryption\Encrypter::class, $encrypter);
    $app->bind(\McpHub\Laravel\Contracts\ActorResolver::class, ActorResolver::class);
    $capsule = new Capsule($app);
    $capsule->addConnection(['driver' => 'pgsql', 'host' => $settings['host'], 'port' => $settings['port'],
        'database' => $settings['database'], 'username' => $settings['username'], 'password' => $settings['password'],
        'charset' => 'utf8', 'prefix' => '', 'schema' => $settings['schema'], 'search_path' => $settings['schema'],
        'sslmode' => 'prefer'], 'testing');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $config->set('database.default', 'testing');
    $app->bind('db.schema', function () { return DB::connection()->getSchemaBuilder(); });
    $fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'status' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['paid', 'pending']],
        'total' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10000]];
    $commands = [];
    foreach (['create', 'update', 'delete'] as $operation) {
        $properties = $operation === 'delete' ? [] : $fields;
        if ($operation !== 'create') { $properties['ref'] = ['type' => 'string', 'maxLength' => 1024]; }
        $commands[$operation] = ['handler' => GenericOrderCommands::class, 'input_schema' => ['type' => 'object',
            'additionalProperties' => false, 'properties' => $properties, 'required' => $operation === 'create' ? ['title'] : ['ref']]];
    }
    $config->set('mcp-hub.enabled', true);
    $config->set('mcp-hub.app_key', 'your_app');
    $config->set('mcp-hub.environment', 'testing');
    $config->set('mcp-hub.source_url', 'http://source.test');
    $config->set('mcp-hub.commands.enabled', true);
    $config->set('queue.default', 'sync');
    $config->set('queue.connections.sync.driver', 'sync');
    $config->set('mcp-hub.exports.enabled', true);
    $config->set('mcp-hub.models', ['orders' => ['model' => Order::class, 'policy' => OrderWritePolicy::class,
        'enabled' => true, 'read' => true, 'fields' => ['title' => 'string', 'status' => 'string', 'total' => 'number'],
        'writable_fields' => ['title', 'status', 'total'], 'commands' => $commands, 'exports' => ['csv']]]);
    if ($action === 'init') {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('name'); $table->string('email'); $table->string('password');
            $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('user_id'); $table->string('title');
            $table->string('status'); $table->decimal('total', 12, 2); $table->string('private_note'); $table->timestamps();
        });
        require __DIR__.'/../../database/migrations/2026_10_29_000001_create_mcp_hub_commands_table.php';
        (new \CreateMcpHubCommandsTable())->up();
        require __DIR__.'/../../database/migrations/2026_10_29_000003_create_mcp_hub_exports_table.php';
        (new \CreateMcpHubExportsTable())->up();
        DB::statement('CREATE TABLE fixture_write_audit (id BIGSERIAL PRIMARY KEY)');
        DB::unprepared('CREATE FUNCTION fixture_audit_update() RETURNS TRIGGER LANGUAGE plpgsql AS $$ BEGIN INSERT INTO fixture_write_audit DEFAULT VALUES; RETURN NEW; END; $$');
        DB::statement('CREATE TRIGGER fixture_order_update AFTER UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION fixture_audit_update()');
        foreach ([1, 2] as $id) {
            User::query()->create(['id' => $id, 'name' => 'Dummy '.$id, 'email' => $id.'@example.test', 'password' => 'unused']);
            Order::query()->create(['user_id' => $id, 'title' => 'Order '.$id, 'status' => 'paid', 'total' => 100, 'private_note' => 'unused']);
        }
        echo json_encode(['initialized' => true]);
        exit;
    }
    if ($action === 'inspect') {
        echo json_encode(['writes' => DB::table('fixture_write_audit')->count(), 'proposals' => DB::table('mcp_hub_commands')->count(),
            'committed' => DB::table('mcp_hub_commands')->where('status', 'committed')->count(),
            'exports' => DB::table('mcp_hub_exports')->count()]);
        exit;
    }
    $actor = app(\McpHub\Laravel\Contracts\ActorResolver::class)->forUser(User::query()->findOrFail((int) ($argv[5] ?? 1)));
    $service = app(Commands::class);
    $barrier = getenv('MCP_HUB_TEST_BARRIER');
    if ($barrier) {
        if (realpath(dirname($barrier)) !== realpath(dirname($argv[2]))) { exit(2); }
        file_put_contents($barrier.'.'.getmypid().'.ready', 'ready');
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'.go')) {
            if (microtime(true) > $deadline) { throw new \RuntimeException('Fixture barrier expired.'); }
            usleep(1000);
        }
    }
    if ($action === 'reference') {
        echo json_encode(['record_ref' => app(\McpHub\Laravel\Security\RecordReferences::class)->encode('orders', $actor, 1)]);
        exit;
    }
    if ($action === 'export') {
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturn(null);
        $app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $result = app(\McpHub\Laravel\Exports\Exports::class)->prepare($actor, 'orders', ['format' => 'csv'], $argv[4]);
        echo json_encode(['status' => $result['status'], 'export_ref' => $result['export_ref']]);
        exit;
    } elseif ($action === 'prepare') {
        $ref = $argv[7] ?? app(\McpHub\Laravel\Security\RecordReferences::class)->encode('orders', $actor, 1);
        $result = $service->prepare($actor, 'orders', 'update', ['ref' => $ref, 'title' => $argv[4]], $argv[6] ?? '');
    } elseif ($action === 'confirm') {
        $result = $service->confirm($actor, $argv[4], 'approve');
    } else { exit(2); }
    echo json_encode(['status' => $result['status'], 'proposal_ref' => $result['proposal_ref']]);
} catch (CommandException $error) {
    echo json_encode(['status' => 'denied', 'code' => $error->status()]);
} catch (\McpHub\Laravel\Exports\ExportException $error) {
    echo json_encode(['status' => 'denied', 'code' => $error->status()]);
} catch (Throwable $error) {
    // Never include a database DSN, query binding, identity or exception chain.
    echo json_encode(['status' => 'worker_failed', 'kind' => get_class($error)]);
    exit(1);
}
