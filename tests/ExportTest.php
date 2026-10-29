<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Exports\BuildExport;
use McpHub\Laravel\Exports\ExportException;
use McpHub\Laravel\Exports\Exports;
use McpHub\Laravel\Exports\ReportWriter;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Tests\Fixtures\ExportPolicy;
use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\User;

class ExportTest extends TestCase
{
    private $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../database/migrations/2026_10_29_000003_create_mcp_hub_exports_table.php';
        (new \CreateMcpHubExportsTable())->up();
        $this->originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($this->originalStorage.'/framework/testing/exports-'.bin2hex(random_bytes(8)));
        config(['mcp-hub.enabled' => true, 'mcp-hub.app_key' => 'your_app', 'mcp-hub.environment' => 'testing',
            'mcp-hub.source_url' => 'http://source.test', 'mcp-hub.require_https' => false,
            'mcp-hub.app_token' => str_repeat('a', 32), 'mcp-hub.delegation_secret' => str_repeat('d', 32),
            'mcp-hub.exports.enabled' => true, 'mcp-hub.exports.connection' => 'sync',
            'mcp-hub.web.export_gate' => 'mcp-hub.export',
            'mcp-hub.models.orders.policy' => ExportPolicy::class,
            'mcp-hub.models.orders.exports' => ['csv', 'xlsx', 'pdf']]);
        foreach ([1, 2] as $id) {
            User::query()->create(['id' => $id, 'name' => 'Dummy '.$id, 'email' => $id.'@example.test', 'password' => 'unused']);
            Order::query()->create(['user_id' => $id, 'title' => 'Order '.$id, 'status' => 'paid',
                'total' => $id * 100, 'private_note' => 'private-canary-'.$id]);
        }
    }

    protected function tearDown(): void
    {
        $directory = $this->app->storagePath();
        $this->app->useStoragePath($this->originalStorage);
        app('files')->deleteDirectory($directory);
        parent::tearDown();
    }

    private function actor(int $id = 1)
    {
        return app(ActorResolver::class)->forUser(User::query()->findOrFail($id));
    }

    private function create(array $input = [], string $key = 'dummy-export-idempotency-key'): array
    {
        return app(Exports::class)->prepare($this->actor(), 'orders', $input + ['format' => 'csv'], $key);
    }

    public function test_exports_are_closed_by_default_and_require_explicit_policy_and_format(): void
    {
        config(['mcp-hub.exports.enabled' => false]);
        $manifest = app(Registry::class)->manifest(app(Connection::class));
        $this->assertCount(3, $manifest['modules'][0]['read_endpoints']);
        $this->expectException(ExportException::class);
        $this->create();
    }

    public function test_actor_scoped_report_is_encrypted_and_repeated_request_returns_one_artifact(): void
    {
        $receipt = $this->create();
        $this->assertSame('ready', $receipt['status']);
        $this->assertSame(1, $receipt['row_count']);
        $this->assertSame($receipt, $this->create());
        $this->assertSame(1, DB::table('mcp_hub_exports')->count());
        list($format, $bytes) = app(Exports::class)->download($this->actor(), $receipt['export_ref']);
        $this->assertSame('csv', $format);
        $this->assertStringContainsString('Order 1', $bytes);
        $this->assertStringNotContainsString('Order 2', $bytes);
        $this->assertStringNotContainsString('private-canary', $bytes);
        $this->assertStringNotContainsString('user_id', $bytes);
        $this->assertStringNotContainsString('Order 1', DB::table('mcp_hub_exports')->first()->payload);
        $encrypted = file_get_contents(storage_path('app/mcp-hub/exports/'.$receipt['export_ref'].'.enc'));
        $this->assertStringNotContainsString('Order 1', $encrypted);
        $this->assertStringStartsWith('http://source.test/mcp-hub/exports/exp_', $receipt['download_url']);
        $this->expectException(ExportException::class);
        app(Exports::class)->download($this->actor(2), $receipt['export_ref']);
    }

    public function test_idempotency_conflict_and_active_capacity_are_bounded(): void
    {
        $this->create();
        try {
            $this->create(['columns' => 'title']);
            $this->fail('Conflicting input must be rejected.');
        } catch (ExportException $error) {
            $this->assertSame(409, $error->status());
        }
        $this->create([], 'dummy-export-second-key');
        $this->expectException(ExportException::class);
        $this->create([], 'dummy-export-third-key');
    }

    public function test_record_change_and_ownership_change_invalidate_download(): void
    {
        $receipt = $this->create();
        foreach (['title' => 'Changed order', 'user_id' => 2] as $field => $value) {
            Order::query()->where('id', 1)->update([$field => $value]);
            try {
                app(Exports::class)->download($this->actor(), $receipt['export_ref']);
                $this->fail('Changed snapshot must not be downloadable.');
            } catch (ExportException $error) {
                $this->assertSame(409, $error->status());
            }
        }
    }

    public function test_expiry_and_field_grant_revocation_deny_download(): void
    {
        $receipt = $this->create();
        config(['mcp-hub.resource_grants' => ['orders' => ['enabled' => true, 'fields' => ['title'], 'operations' => []]]]);
        try {
            app(Exports::class)->download($this->actor(), $receipt['export_ref']);
            $this->fail('Changed field permissions must deny download.');
        } catch (ExportException $error) {
            $this->assertSame(409, $error->status());
        }
        config(['mcp-hub.resource_grants' => null]);
        DB::table('mcp_hub_exports')->update(['expires_at' => time() - 1]);
        $this->expectException(ExportException::class);
        app(Exports::class)->download($this->actor(), $receipt['export_ref']);
    }

    public function test_worker_rechecks_account_and_runs_only_once(): void
    {
        $jobs = [];
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use (&$jobs) { $jobs[] = $job; });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $receipt = $this->create();
        $this->assertSame('pending', $receipt['status']);
        $this->assertInstanceOf(BuildExport::class, $jobs[0]);
        $this->assertStringNotContainsString('demo_user', serialize($jobs[0]));
        User::query()->where('id', 1)->update(['active' => false]);
        $jobs[0]->handle(app(Exports::class));
        $jobs[0]->handle(app(Exports::class));
        $this->assertSame('failed', DB::table('mcp_hub_exports')->first()->status);
        $this->assertSame('', DB::table('mcp_hub_exports')->first()->payload);
    }

    public function test_grouped_metrics_and_partial_coverage_are_explicit(): void
    {
        Order::query()->create(['user_id' => 1, 'title' => 'Extra', 'status' => 'paid', 'total' => 40, 'private_note' => 'unused']);
        $receipt = $this->create(['metric' => 'sum', 'field' => 'total', 'group_by' => 'status', 'limit' => 1]);
        $this->assertSame('partial', $receipt['coverage']);
        list($format, $bytes) = app(Exports::class)->download($this->actor(), $receipt['export_ref']);
        $this->assertStringContainsString('partial', $bytes);
        $this->assertStringContainsString('"status","sum"'."\n".'"paid","100"', str_replace("\r", '', $bytes));
    }

    public function test_values_with_pii_fail_closed_without_record_or_error_leak(): void
    {
        Order::query()->where('id', 1)->update(['title' => 'private.person@example.test']);
        $receipt = $this->create();
        $this->assertSame('failed', $receipt['status']);
        $this->assertNull($receipt['download_url']);
        $this->assertSame('', DB::table('mcp_hub_exports')->first()->payload);
    }

    public function test_csv_formula_and_xlsx_inline_strings_are_safe_and_pdf_is_renderable(): void
    {
        $writer = app(ReportWriter::class);
        $columns = ['title' => 'string', 'total' => 'number'];
        $rows = [['title' => '=SUM(1,2)', 'total' => 125.5], ['title' => 'Café <b>plain text</b>', 'total' => 0]];
        $directory = __DIR__.'/../build/export-fixtures';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $bytes = $writer->render($format, $columns, $rows, 'complete', '2026-10-29T00:00:00+00:00');
            file_put_contents($directory.'/report.'.$format, $bytes);
            if ($format === 'csv') {
                $this->assertStringContainsString("'=SUM(1,2)", $bytes);
            } elseif ($format === 'xlsx') {
                $this->assertStringStartsWith("PK\x03\x04", $bytes);
                $this->assertStringContainsString('t="inlineStr"', $bytes);
                $this->assertStringNotContainsString('<f>', $bytes);
                $this->assertStringContainsString('&lt;b&gt;', $bytes);
            } else {
                $this->assertStringStartsWith('%PDF-', $bytes);
            }
        }
    }

    public function test_source_assertion_is_required_and_service_key_cannot_download(): void
    {
        $this->postJson('/api/ai/v1/orders/exports', ['format' => 'csv'])->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '.str_repeat('a', 32)])
            ->postJson('/api/ai/v1/orders/exports', ['format' => 'csv'])->assertStatus(403);
        $receipt = $this->create();
        $this->get('/mcp-hub/exports/'.$receipt['export_ref'])->assertStatus(403);
        $this->actingAs(User::query()->findOrFail(1));
        $this->get('/mcp-hub/exports/'.$receipt['export_ref'])->assertStatus(403);
        Gate::define('mcp-hub.export', function ($user) { return (int) $user->getAuthIdentifier() === 1; });
        $this->assertNotNull(app(\McpHub\Laravel\Security\WebAccess::class)->user('export_gate'));
        $this->get('/mcp-hub/exports/'.$receipt['export_ref'])->assertStatus(200)
            ->assertHeader('Content-Disposition', 'attachment; filename="report.csv"');
    }

    public function test_valid_source_call_uses_closed_scalar_contract_and_manifest_safe_query(): void
    {
        $json = json_encode(['app_key' => 'your_app', 'tenant_id' => 'demo_account_1', 'user_ref' => 'demo_user_1',
            'aud' => 'http://source.test', 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(20))], JSON_UNESCAPED_SLASHES);
        $encode = function ($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
        $assertion = $encode($json).'.'.$encode(hash_hmac('sha256', $json, str_repeat('d', 32), true));
        $this->withHeaders(['Authorization' => 'Bearer '.str_repeat('a', 32), 'X-Hub-Assertion' => $assertion,
            'Idempotency-Key' => 'dummy-source-export-key'])->postJson('/api/ai/v1/orders/exports', ['format' => 'csv'])
            ->assertStatus(200)->assertJson(['success' => true, 'data' => [['status' => 'ready']]]);
        $routes = app(Registry::class)->manifest(app(Connection::class))['modules'][0]['read_endpoints'];
        $directory = __DIR__.'/../build/export-fixtures';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/manifest.json', json_encode(app(Registry::class)->manifest(app(Connection::class)), JSON_PRETTY_PRINT));
        $this->assertTrue($routes[3]['safe_query']);
        $this->assertSame('POST', $routes[3]['method']);
        $this->assertSame('GET', $routes[4]['method']);
    }

    public function test_invalid_inputs_cannot_select_identity_fields_or_unbounded_jobs(): void
    {
        foreach ([['format' => 'html'], ['columns' => 'user_id'], ['columns' => 'private_note'],
            ['columns' => 'title,title'], ['columns' => ''], ['limit' => 501], ['status' => 'unknown'],
            ['metric' => 'sum', 'field' => 'title'], ['metric' => 'sum', 'field' => 'total', 'columns' => 'title'],
            ['metric' => 'records', 'group_by' => 'status'], ['metric' => 'count', 'field' => 'total'],
            ['approved' => true], ['format' => 'pdf', 'limit' => 101]] as $input) {
            try {
                $this->create($input);
                $this->fail('Invalid export input must be refused.');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            } catch (ExportException $error) {
                $this->assertSame(422, $error->status());
            }
        }
        $this->assertSame(0, DB::table('mcp_hub_exports')->count());
    }

    public function test_export_requires_host_policy_permission_even_when_read_is_allowed(): void
    {
        config(['mcp-hub.models.orders.policy' => \McpHub\Laravel\Tests\Fixtures\OrderPolicy::class]);
        $this->expectException(ExportException::class);
        $this->create();
    }

    public function test_production_rejects_sync_queue_before_storing_a_job(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->create();
            $this->fail('Production exports need an asynchronous queue.');
        } catch (ExportException $error) {
            $this->assertSame(503, $error->status());
        }
        $this->assertSame(0, DB::table('mcp_hub_exports')->count());
    }

    public function test_expired_pruning_removes_artifacts_and_identity_payload_only(): void
    {
        $receipt = $this->create();
        $path = storage_path('app/mcp-hub/exports/'.$receipt['export_ref'].'.enc');
        $this->assertFileExists($path);
        DB::table('mcp_hub_exports')->update(['expires_at' => time() - 1]);
        app(Exports::class)->prune();
        $this->assertFalse(is_file($path));
        $this->assertSame('', DB::table('mcp_hub_exports')->first()->payload);
        $this->assertSame('expired', DB::table('mcp_hub_exports')->first()->status);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, User::query()->count());
    }

    public function test_queue_dispatch_failure_returns_only_failed_receipt(): void
    {
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private-broker-credential-canary'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $receipt = $this->create();
        $this->assertSame('failed', $receipt['status']);
        $this->assertStringNotContainsString('private-broker', json_encode($receipt));
        $this->assertSame('', DB::table('mcp_hub_exports')->first()->payload);
    }

    public function test_record_and_group_column_order_are_preserved(): void
    {
        $receipt = $this->create(['columns' => 'total,title']);
        list($format, $bytes) = app(Exports::class)->download($this->actor(), $receipt['export_ref']);
        $this->assertStringContainsString('"total","title"'."\r\n".'"100","Order 1"', $bytes);
        $aggregate = $this->create(['metric' => 'avg', 'field' => 'total', 'group_by' => 'status,title'], 'dummy-export-group-order');
        list($format, $bytes) = app(Exports::class)->download($this->actor(), $aggregate['export_ref']);
        $this->assertStringContainsString('"status","title","avg"'."\r\n".'"paid","Order 1","100"', $bytes);
    }
}
