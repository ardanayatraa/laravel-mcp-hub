<?php

namespace McpHub\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Http\ConnectorController;

final class McpHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/mcp-hub.php', 'mcp-hub');
        $this->app->bind(ActorResolver::class, function ($app) {
            $resolver = $app->make($app['config']->get('mcp-hub.actor_resolver'));
            if (! $resolver instanceof ActorResolver) {
                throw new \RuntimeException('Invalid actor resolver.');
            }
            return $resolver;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'mcp-hub');
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'mcp-hub');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->publishes([dirname(__DIR__).'/config/mcp-hub.php' => config_path('mcp-hub.php')], 'mcp-hub-config');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\InstallCommand::class, Console\ConnectCommand::class, Console\DoctorCommand::class,
                Console\PruneCommand::class, Console\DiscoverCommand::class, Console\MakeResourceCommand::class,
                Console\StatusCommand::class, Console\SyncCommand::class, Console\DisconnectCommand::class]);
            $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
                if (config('mcp-hub.maintenance.scheduled_prune', true) === true) {
                    $schedule->command('mcp-hub:prune')->hourly()->withoutOverlapping(15);
                }
            });
        }
        if (config('mcp-hub.routes.enabled') && ! $this->app->routesAreCached()) {
            Route::prefix(trim(config('mcp-hub.routes.prefix'), '/'))->middleware('throttle:60,1')
                ->name('mcp-hub.')->group(function () {
                    Route::get('health', [ConnectorController::class, 'health'])->name('health');
                    Route::get('capabilities', [ConnectorController::class, 'capabilities'])->name('capabilities');
                    Route::post('{resource}/exports', [Http\ExportController::class, 'prepare'])
                        ->where('resource', '[a-z][a-z0-9_]{0,47}')->name('exports.prepare');
                    Route::get('{resource}/exports/{reference}', [Http\ExportController::class, 'status'])
                        ->where('resource', '[a-z][a-z0-9_]{0,47}')->where('reference', 'exp_[a-p]{48}')->name('exports.status');
                    Route::post('{resource}/commands/{operation}', [Http\CommandController::class, 'prepare'])
                        ->where('resource', '[a-z][a-z0-9_]{0,47}')->where('operation', 'create|update|delete')->name('commands.prepare');
                    Route::get('{resource}/commands/{proposal}', [Http\CommandController::class, 'status'])
                        ->where('resource', '[a-z][a-z0-9_]{0,47}')->where('proposal', 'prop_[a-p]{48}')->name('commands.status');
                    Route::get('{resource}/{action}', [ConnectorController::class, 'read'])
                        ->where('resource', '[a-z][a-z0-9_]{0,47}')->where('action', 'list|get|count')->name('read');
                });
        }
        if (config('mcp-hub.web.enabled') && ! $this->app->routesAreCached()) {
            Route::get(trim(config('mcp-hub.web.prefix'), '/').'/assets/{asset}', [Http\AssetController::class, 'show'])
                ->where('asset', 'chat\.js|chat\.css|settings\.js')->name('mcp-hub.asset');
            Route::prefix(trim(config('mcp-hub.web.prefix'), '/'))->middleware(['web', Http\WebBoundary::class, 'throttle:30,1'])
                ->name('mcp-hub.')->group(function () {
                    Route::get('/', [Http\SettingsController::class, 'show'])->name('settings.show');
                    Route::post('settings', [Http\SettingsController::class, 'save'])->name('settings.save');
                    Route::post('settings/connect', [Http\SettingsController::class, 'connect'])->name('settings.connect');
                    Route::post('settings/resume', [Http\SettingsController::class, 'resume'])->name('settings.resume');
                    Route::post('settings/sync', [Http\SettingsController::class, 'sync'])->name('settings.sync');
                    Route::post('settings/check', [Http\SettingsController::class, 'check'])->name('settings.check');
                    Route::get('chat', [Http\ChatController::class, 'show'])->name('chat.show');
                    Route::get('exports/{reference}', [Http\ExportController::class, 'download'])
                        ->where('reference', 'exp_[a-p]{48}')->name('exports.download');
                    Route::get('models', [Http\ChatController::class, 'models'])->name('chat.models');
                    Route::post('chat', [Http\ChatController::class, 'turn'])->name('chat.turn');
                    Route::get('proposals/{proposal}', [Http\ApprovalController::class, 'show'])->name('approval.show');
                    Route::post('proposals/{proposal}', [Http\ApprovalController::class, 'confirm'])->name('approval.confirm');
                });
        }
    }
}
