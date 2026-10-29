<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use McpHub\Laravel\Discovery;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Tests\Fixtures\Order;

class DeveloperToolsTest extends TestCase
{
    public function test_discovery_reads_only_schema_and_never_constructs_models(): void
    {
        $namespace = trim(app()->getNamespace(), '\\').'\\Models';
        $file = app_path('Models/McpUnbootableCandidate.php');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, '<?php namespace '.$namespace.';
            class McpUnbootableCandidate extends \\Illuminate\\Database\\Eloquent\\Model {
                protected $table = "orders";
                protected $hidden = ["private_note"];
                public function __construct(array $attributes = []) { throw new \\RuntimeException("Model must not be constructed"); }
            }');
        require_once $file;
        DB::connection()->enableQueryLog();
        try {
            $rows = app(Discovery::class)->candidates(true);
            $match = array_values(array_filter($rows, function ($row) use ($namespace) {
                return $row['model'] === $namespace.'\\McpUnbootableCandidate';
            }));
            $this->assertCount(1, $match);
            $this->assertFalse($match[0]['enabled']);
            $this->assertContains('title', $match[0]['fields']);
            $this->assertNotContains('private_note', $match[0]['fields']);
            $this->assertNotContains('user_id', $match[0]['fields']);
            foreach (DB::connection()->getQueryLog() as $query) {
                $this->assertSame(0, preg_match('/select\s+.+\s+from\s+["`]?orders["`]?/i', $query['query']));
            }
        } finally {
            DB::connection()->disableQueryLog();
            unlink($file);
        }
    }

    public function test_generator_is_closed_repeatable_and_preserves_existing_files(): void
    {
        $paths = [app_path('Mcp/McpGeneratedOrdersPolicy.php'), app_path('Mcp/McpGeneratedOrdersCommands.php'),
            config_path('mcp-hub-resources/mcp_generated_orders.php')];
        $options = ['resource' => 'mcp_generated_orders', '--model' => Order::class];
        try {
            $this->assertSame(0, Artisan::call('make:mcp-resource', $options + ['--dry-run' => true]));
            foreach ($paths as $path) {
                $this->assertFalse(file_exists($path));
            }
            $this->assertSame(0, Artisan::call('make:mcp-resource', $options));
            $definition = require $paths[2];
            $this->assertFalse($definition['enabled']);
            $this->assertFalse($definition['read']);
            $this->assertSame([], $definition['fields']);
            $this->assertSame([], $definition['commands']);
            $this->assertStringContainsString('return false;', file_get_contents($paths[0]));
            $this->assertStringContainsString("whereRaw('1 = 0')", file_get_contents($paths[0]));
            $before = array_map('hash_file', array_fill(0, count($paths), 'sha256'), $paths);
            $this->assertSame(1, Artisan::call('make:mcp-resource', $options));
            $this->assertSame($before, array_map('hash_file', array_fill(0, count($paths), 'sha256'), $paths));
            config(['mcp-hub.models' => [], 'mcp-hub-resources.mcp_generated_orders' => $definition]);
            $this->assertSame([], app(Registry::class)->resources());
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_resource_files_cannot_silently_override_existing_definitions(): void
    {
        config(['mcp-hub-resources.orders' => config('mcp-hub.models.orders')]);
        $this->expectException(\InvalidArgumentException::class);
        app(Registry::class)->resources();
    }

    public function test_generator_rejects_path_and_namespace_injection(): void
    {
        $this->assertSame(2, Artisan::call('make:mcp-resource', ['resource' => '../escape', '--model' => Order::class]));
        $this->assertSame(2, Artisan::call('make:mcp-resource', ['resource' => 'orders', '--model' => "App\\Order'; phpinfo();"]));
    }
}
