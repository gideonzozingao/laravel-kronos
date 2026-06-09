<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Config File Path
    |--------------------------------------------------------------------------
    | The path where Kronos writes the canonical kronos.yaml file.
    | On single-node setups this is the source of truth.
    | On multi-node, Redis takes precedence and the file is a fallback.
    */
    'config_path' => storage_path('kronos.yaml'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Node Mode
    |--------------------------------------------------------------------------
    | When true, all schedule entries are registered with onOneServer()
    | and Redis is used as the canonical config store.
    | Enable this for ECS, Kubernetes, or any multi-replica deployment.
    */
    'multi_node' => env('KRONOS_MULTI_NODE', false),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    | The Redis connection name (from config/database.php) to use for
    | config storage, distributed locking, and pub/sub invalidation.
    */
    'redis_connection' => env('KRONOS_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    | The queue connection and name for Kronos internal jobs
    | (RebuildKronosConfig, ExecuteWorkflowStep).
    */
    'queue' => [
        'connection' => env('KRONOS_QUEUE_CONNECTION', 'redis'),
        'name' => env('KRONOS_QUEUE_NAME', 'kronos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Trigger
    |--------------------------------------------------------------------------
    | Enable the inbound webhook endpoint for triggering workflows via HTTP.
    | Secure the endpoint with the secret token below.
    */
    'webhook' => [
        'enabled' => env('KRONOS_WEBHOOK_ENABLED', false),
        'secret' => env('KRONOS_WEBHOOK_SECRET'),
        'prefix' => env('KRONOS_WEBHOOK_PREFIX', 'kronos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Run History Retention
    |--------------------------------------------------------------------------
    | How long (in days) to keep workflow and schedule run history.
    | Set to null to keep forever.
    */
    'retention_days' => env('KRONOS_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Filament Panel
    |--------------------------------------------------------------------------
    | Configuration for the optional Kronos Filament UI plugin.
    */
    'filament' => [
        'enabled' => env('KRONOS_FILAMENT_ENABLED', true),
        'panel_id' => env('KRONOS_FILAMENT_PANEL', 'admin'),
        'nav_group' => 'Kronos',
        'nav_sort' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Timezone
    |--------------------------------------------------------------------------
    | Default timezone applied to all schedules and workflows
    | unless overridden per-entry.
    */
    'timezone' => env('KRONOS_TIMEZONE', config('app.timezone', 'UTC')),
    /*
    |--------------------------------------------------------------------------
    | ReactPHP Daemon
    |--------------------------------------------------------------------------
    | When enabled, the ReactPHP daemon replaces crontab-based scheduling
    | with a persistent event-loop process. Start it with:
    |   php artisan kronos:daemon
    |
    | Run EXACTLY ONE daemon instance per deployment. Do not run alongside
    | `schedule:run` — they will double-fire every cron entry.
    */
    'reactphp' => [

        // Master switch — enables daemon mode and disables native scheduler hydration
        'enabled' => env('KRONOS_REACTPHP_ENABLED', false),

        'websocket' => [
            // Enable the WebSocket server for live Filament dashboard updates
            'enabled' => env('KRONOS_WS_ENABLED', false),

            // Port the WebSocket server listens on
            'port' => env('KRONOS_WS_PORT', 6001),

            // Allowed origins for WebSocket connections (null = allow all)
            'allowed_origins' => env('KRONOS_WS_ORIGINS'),
        ],

        'scheduler' => [
            // Tick interval in seconds — how often to evaluate due cron entries
            // Default 1.0 gives sub-minute precision
            'tick_interval' => (float) env('KRONOS_SCHEDULER_TICK', 1.0),

            // Backstop config reload interval in seconds
            // The Redis subscriber is the primary reload mechanism;
            // this is a fallback for missed pub/sub messages
            'reload_interval' => (float) env('KRONOS_SCHEDULER_RELOAD', 60.0),
        ],
    ],

];
