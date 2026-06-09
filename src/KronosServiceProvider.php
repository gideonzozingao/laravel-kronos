<?php

namespace ZuqongTech\Kronos;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ZuqongTech\Kronos\Console\Commands\KronosCancelCommand;
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
use ZuqongTech\Kronos\Writers\KronosConfigWriter;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

class KronosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kronos.php', 'kronos');

        $this->app->singleton(KronosRuleEngine::class);
        $this->app->singleton(KronosOrchestrator::class);
        $this->app->singleton(KronosConfigWriter::class);
        $this->app->singleton(RedisConfigStore::class);
        $this->app->singleton(DAGResolver::class);
        $this->app->singleton(KronosScheduleLoader::class);

        // Fix #20: bind observer through the container so it can receive
        // future constructor dependencies via DI
        $this->app->bind(KronosModelObserver::class, fn ($app) => new KronosModelObserver(
            $app->make(KronosRuleEngine::class),
        ));

        $this->app->singleton('kronos', fn ($app) => new Kronos(
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
                KronosCancelCommand::class, // Fix #23
                KronosPruneCommand::class,  // Fix #22
            ]);
        }
    }

    protected function registerSchedule(): void
    {
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
