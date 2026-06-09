<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\ReactPHP\Daemon;

use React\EventLoop\LoopInterface;
use ZuqongTech\Kronos\ReactPHP\Contracts\KronosDaemonComponent;
use ZuqongTech\Kronos\ReactPHP\Loop\LoopManager;
use ZuqongTech\Kronos\ReactPHP\Scheduler\ReactScheduler;
use ZuqongTech\Kronos\ReactPHP\Subscriber\ReactRedisSubscriber;
use ZuqongTech\Kronos\ReactPHP\WebSocket\KronosWebSocketServer;

/**
 * KronosDaemon — the long-running process that powers the ReactPHP layer.
 *
 * Wires together all KronosDaemonComponent instances, attaches them to the
 * shared event loop, installs signal handlers (SIGTERM / SIGINT), and runs
 * the loop until shutdown.
 *
 * Started via: php artisan kronos:daemon
 *
 * What replaces what:
 *   BEFORE (crontab)          AFTER (daemon)
 *   ─────────────────────     ──────────────────────────────────────
 *   * * * * * schedule:run  → ReactScheduler (1-second tick)
 *   Redis::subscribe poll   → ReactRedisSubscriber (instant push)
 *   HTTP polling dashboard  → KronosWebSocketServer (live stream)
 */
class KronosDaemon
{
    /** @var KronosDaemonComponent[] */
    protected array $components = [];

    protected LoopInterface $loop;

    public function __construct(
        protected ReactScheduler $scheduler,
        protected ReactRedisSubscriber $subscriber,
        protected KronosWebSocketServer $wsServer,
    ) {
        $this->loop = LoopManager::get();
    }

    /**
     * Boot all components and start the event loop.
     * This method blocks until the daemon is stopped.
     */
    public function run(): void
    {
        $this->registerComponents();
        $this->attachComponents();
        $this->installSignalHandlers();

        echo '[Kronos] Daemon started. Press Ctrl+C to stop.'.PHP_EOL;

        $this->loop->run(); // ← blocks here until stop() is called

        echo '[Kronos] Daemon stopped.'.PHP_EOL;
    }

    /**
     * Register all daemon components in boot order.
     * Order matters: scheduler must be registered before subscriber
     * so the subscriber can call scheduler->reloadConfig().
     */
    protected function registerComponents(): void
    {
        $this->components = [
            $this->scheduler,
            $this->subscriber,
            $this->wsServer,
        ];
    }

    /**
     * Attach each component to the event loop.
     * Components register their timers and I/O handlers here.
     */
    protected function attachComponents(): void
    {
        foreach ($this->components as $component) {
            $component->attach($this->loop);
        }
    }

    /**
     * Graceful shutdown — called on SIGTERM or SIGINT.
     */
    protected function shutdown(): void
    {
        echo PHP_EOL.'[Kronos] Shutdown signal received. Stopping...'.PHP_EOL;

        foreach (array_reverse($this->components) as $kronosDaemonComponent) {
            $kronosDaemonComponent->detach();
        }

        $this->loop->stop();
    }

    /**
     * Install POSIX signal handlers so the daemon shuts down gracefully
     * on Ctrl+C or `docker stop` / `kill`.
     */
    protected function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            echo '[Kronos] Warning: pcntl extension not available — signal handling disabled.'.PHP_EOL;

            return;
        }

        $this->loop->addSignal(SIGTERM, fn () => $this->shutdown());
        $this->loop->addSignal(SIGINT, fn () => $this->shutdown());

        echo '[Kronos] Signal handlers installed (SIGTERM, SIGINT).'.PHP_EOL;
    }
}
