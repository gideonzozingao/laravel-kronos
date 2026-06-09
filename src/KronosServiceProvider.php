<?php

namespace ZuqongTech\Kronos;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ZuqongTech\Kronos\Console\Commands\KronosCancelCommand;
use ZuqongTech\Kronos\Console\Commands\KronosDaemonCommand;
use ZuqongTech\Kronos\Console\Commands\KronosInstallCommand;
use ZuqongTech\Kronos\Console\Commands\KronosListCommand;
use ZuqongTech\Kronos\Console\Commands\KronosPruneCommand;
use ZuqongTech\Kronos\Console\Commands\KronosRunWorkflowCommand;
use ZuqongTech\Kronos\Console\Commands\KronosStatusCommand;
use ZuqongTech\Kronos\Console\Commands\KronosTriggerCommand;
use ZuqongTech\Kronos\DAG\DAGResolver;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Engine\KronosScheduleLoader;
use ZuqongTech\Kronos\Observers\KronosModelObserver;
use ZuqongTech\Kronos\ReactPHP\Broadcast\RunBroadcaster;
use ZuqongTech\Kronos\ReactPHP\Daemon\KronosDaemon;
use ZuqongTech\Kronos\ReactPHP\Scheduler\ReactScheduler;
use ZuqongTech\Kronos\ReactPHP\Subscriber\ReactRedisSubscriber;
use ZuqongTech\Kronos\ReactPHP\WebSocket\KronosWebSocketServer;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

class KronosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kronos.php', 'kronos');

        // ── Core engine singletons ─────────────────────────────────────────
        $this->app->singleton(KronosRuleEngine::class);
        $this->app->singleton(KronosConfigWriter::class);
        $this->app->singleton(RedisConfigStore::class);
        $this->app->singleton(DAGResolver::class);
        $this->app->singleton(KronosScheduleLoader::class);

        // ── Observer — container-bound for future DI ───────────────────────
        $this->app->bind(KronosModelObserver::class, fn ($app): KronosModelObserver => new KronosModelObserver(
            $app->make(KronosRuleEngine::class),
        ));

        // ── ReactPHP layer ─────────────────────────────────────────────────
        // All singletons so shared state (subscriptions, loop) is consistent
        $this->app->singleton(KronosWebSocketServer::class);

        $this->app->singleton(RunBroadcaster::class, fn ($app): RunBroadcaster => new RunBroadcaster(
            // Only inject the WS server if ReactPHP is enabled; otherwise null
            config('kronos.reactphp.enabled', false)
                ? $app->make(KronosWebSocketServer::class)
                : null,
        ));

        $this->app->singleton(ReactScheduler::class, fn ($app): ReactScheduler => new ReactScheduler(
            $app->make(KronosOrchestrator::class),
            $app->make(KronosScheduleLoader::class),
            $app->make(RedisConfigStore::class),
        ));

        $this->app->singleton(ReactRedisSubscriber::class, fn ($app): ReactRedisSubscriber => new ReactRedisSubscriber(
            $app->make(ReactScheduler::class),
            config('kronos.reactphp.websocket.enabled', false)
                ? $app->make(KronosWebSocketServer::class)
                : null,
        ));

        $this->app->singleton(KronosDaemon::class, fn ($app): KronosDaemon => new KronosDaemon(
            $app->make(ReactScheduler::class),
            $app->make(ReactRedisSubscriber::class),
            $app->make(KronosWebSocketServer::class),
        ));

        // ── Orchestrator — receives optional RunBroadcaster ───────────────
        $this->app->singleton(KronosOrchestrator::class, fn ($app): KronosOrchestrator => new KronosOrchestrator(
            $app->make(DAGResolver::class),
            $app->make(RunBroadcaster::class),
        ));

        // ── Facade root ────────────────────────────────────────────────────
        $this->app->singleton('kronos', fn ($app): Kronos => new Kronos(
            $app->make(KronosRuleEngine::class),
            $app->make(KronosOrchestrator::class),
            $app->make(KronosConfigWriter::class),
        ));
    }

    public function boot(): void
    {
        $this->publishAssets();
        $this->loadMigrations();
        $this->registerCommands();
        $this->registerSchedule();
        $this->registerRoutes();
    }

    protected function publishAssets(): void
    {
        $this->publishes([
            __DIR__.'/../config/kronos.php' => config_path('kronos.php'),
        ], 'kronos-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kronos-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/kronos'),
        ], 'kronos-views');
    }

    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                KronosInstallCommand::class,
                KronosListCommand::class,
                KronosRunWorkflowCommand::class,
                KronosTriggerCommand::class,
                KronosStatusCommand::class,
                KronosCancelCommand::class,
                KronosPruneCommand::class,
                KronosDaemonCommand::class, // ← ReactPHP daemon
            ]);
        }
    }

    /**
     * Hydrate Laravel's native scheduler — only active when ReactPHP
     * daemon is NOT running (traditional crontab mode).
     */
    protected function registerSchedule(): void
    {
        if (config('kronos.reactphp.enabled', false)) {
            return; // Daemon replaces the crontab entirely
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->app->make(KronosScheduleLoader::class)->hydrate($schedule);
        });
    }

    protected function registerRoutes(): void
    {
        if (config('kronos.webhook.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/kronos.php');
        }
    }
}
