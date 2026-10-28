<?php

namespace McpHub\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use McpHub\Laravel\McpHubServiceProvider;
use McpHub\Laravel\Tests\Fixtures\ActorResolver;
use McpHub\Laravel\Tests\Fixtures\Order;
use McpHub\Laravel\Tests\Fixtures\OrderPolicy;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [McpHubServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->configureEnvironment($app);
    }

    protected function getEnvironmentSetUp($app)
    {
        $this->configureEnvironment($app);
    }

    private function configureEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mcp-hub.actor_resolver', ActorResolver::class);
        $models = ['orders' => [
            'model' => Order::class, 'policy' => OrderPolicy::class,
            'enabled' => true, 'read' => true, 'lookup' => 'title',
            'fields' => ['title' => 'string', 'status' => 'string', 'total' => 'number'],
            'filters' => ['status' => ['paid', 'pending']],
        ]];
        $app['config']->set('mcp-hub.models', $models);
        $app['config']->set('mcp-hub-test.models', $models);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->string('status');
            $table->decimal('total', 12, 2);
            $table->string('private_note');
            $table->timestamps();
        });
    }
}
