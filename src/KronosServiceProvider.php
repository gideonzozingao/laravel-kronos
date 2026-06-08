<?php

namespace ZuqongTech\Kronos;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ZuqongTech\Kronos\Console\Commands\KronosInstallCommand;
use ZuqongTech\Kronos\Console\Commands\KronosListCommand;
use ZuqongTech\Kronos\Console\Commands\KronosRunWorkflowCommand;
use ZuqongTech\Kronos\Console\Commands\KronosStatusCommand;
use ZuqongTech\Kronos\Console\Commands\KronosTriggerCommand;
use ZuqongTech\Kronos\DAG\DAGResolver;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Engine\KronosScheduleLoader;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

class KronosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/kronos.php', 'kronos');

        // Core singletons
        $this->app->singleton(KronosRuleEngine::class);
        $this->app->singleton(KronosOrchestrator::class);
        $this->app->singleton(KronosConfigWriter::class);
        $this->app->singleton(RedisConfigStore::class);
        $this->app->singleton(DAGResolver::class);
        $this->app->singleton(KronosScheduleLoader::class);

        // Facade root
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
        $this->registerObservers();
        $this->registerRoutes();
    }

    protected function publishAssets(): void
    {
        $this->publishes([
            __DIR__ . '/../config/kronos.php' => config_path('kronos.php'),
        ], 'kronos-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'kronos-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/kronos'),
        ], 'kronos-views');
    }

    protected function loadMigrations(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
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
            ]);
        }
    }

    protected function registerSchedule(): void
    {
        // Load schedules and workflows from kronos.yaml / Redis into Laravel's scheduler
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            /** @var KronosScheduleLoader $loader */
            $loader = $this->app->make(KronosScheduleLoader::class);
            $loader->hydrate($schedule);
        });
    }

    protected function registerObservers(): void
    {
        // Observers are registered by the rule engine when rules are defined
        // Models are bound dynamically at rule registration time
    }

    protected function registerRoutes(): void
    {
        if (config('kronos.webhook.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/kronos.php');
        }
    }
}