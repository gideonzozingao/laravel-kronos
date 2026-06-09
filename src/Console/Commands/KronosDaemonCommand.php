<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\ReactPHP\Daemon\KronosDaemon;

/**
 * php artisan kronos:daemon
 *
 * Starts the ReactPHP-powered persistent daemon that replaces:
 *   - The crontab `* * * * * php artisan schedule:run` entry
 *   - Manual Redis pub/sub polling for config invalidation
 *   - HTTP polling for live run status in the Filament dashboard
 *
 * The daemon blocks until SIGTERM or SIGINT is received, then shuts down
 * all components gracefully before exiting.
 *
 * Recommended deployment:
 *   - Run exactly ONE instance per deployment (ECS task, K8s pod, Supervisor worker)
 *   - Do NOT run alongside a crontab schedule:run — they will double-fire
 *   - Use Supervisor or a dedicated ECS task definition with replicas: 1
 *
 * Example Supervisor config:
 *   [program:kronos-daemon]
 *   command=php /var/www/artisan kronos:daemon
 *   autostart=true
 *   autorestart=true
 *   stopwaitsecs=30
 *   numprocs=1
 */
class KronosDaemonCommand extends Command
{
    protected $signature = 'kronos:daemon
                            {--skip-scheduler : Skip the ReactScheduler (cron evaluation)}
                            {--skip-websocket : Skip the WebSocket server}
                            {--skip-subscriber : Skip the Redis pub/sub subscriber}';

    protected $description = 'Start the Kronos ReactPHP daemon (replaces crontab + enables live dashboard)';

    public function handle(KronosDaemon $kronosDaemon): int
    {
        if (!config('kronos.reactphp.enabled', false)) {
            $this->error(
                'ReactPHP mode is disabled. Set KRONOS_REACTPHP_ENABLED=true in your .env to use the daemon.',
            );

            return self::FAILURE;
        }

        $this->info('Starting Kronos daemon...');
        $this->line('  <comment>Scheduler:</comment>  '.($this->option('skip-scheduler') ? 'skipped' : 'enabled'));
        $this->line('  <comment>WebSocket:</comment>  '.(config('kronos.reactphp.websocket.enabled') ? 'enabled on port '.config('kronos.reactphp.websocket.port', 6001) : 'disabled'));
        $this->line('  <comment>Subscriber:</comment> '.($this->option('skip-subscriber') ? 'skipped' : 'enabled'));
        $this->newLine();

        // The daemon's run() method blocks until SIGTERM/SIGINT
        $kronosDaemon->run();

        return self::SUCCESS;
    }
}
