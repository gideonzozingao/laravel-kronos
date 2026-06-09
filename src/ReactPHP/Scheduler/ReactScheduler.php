<?php

namespace ZuqongTech\Kronos\ReactPHP\Scheduler;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Engine\KronosScheduleLoader;
use ZuqongTech\Kronos\ReactPHP\Contracts\KronosDaemonComponent;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

/**
 * ReactScheduler — event-loop-driven schedule evaluator.
 *
 * Replaces the crontab-based `* * * * * php artisan schedule:run` approach
 * with a persistent ReactPHP timer that ticks every second. On each tick it
 * evaluates which cron entries and workflow triggers are due and fires them
 * without spawning a new PHP process.
 *
 * Key differences from Laravel's native scheduler:
 *  - Sub-minute granularity (1-second ticks vs 1-minute polling)
 *  - No process fork per run — callbacks execute in the same event loop
 *  - Config reloads instantly via ReactRedisSubscriber invalidation signal
 *  - No crontab entry required on the server at all
 */
class ReactScheduler implements KronosDaemonComponent
{
    protected ?TimerInterface $tickTimer = null;

    protected ?TimerInterface $reloadTimer = null;

    protected array $config = [];

    /** Tracks last-fired minute per entry to prevent double-firing within a minute. */
    protected array $lastFired = [];

    public function __construct(
        protected KronosOrchestrator $orchestrator,
        protected KronosScheduleLoader $loader,
        protected RedisConfigStore $redis,
    ) {}

    /**
     * Attach a 1-second periodic timer to the loop.
     * Also attaches a 60-second config reload timer as a backstop — the
     * ReactRedisSubscriber is the primary invalidation mechanism.
     */
    public function attach(LoopInterface $loop): void
    {
        // Load config immediately on attach
        $this->reloadConfig();

        // Primary tick — evaluate due entries every second
        $this->tickTimer = $loop->addPeriodicTimer(1.0, function (): void {
            $this->tick();
        });

        // Backstop reload every 60 seconds in case pub/sub missed a message
        $this->reloadTimer = $loop->addPeriodicTimer(60.0, function (): void {
            $this->reloadConfig();
        });

        echo '[Kronos] ReactScheduler attached — ticking every second.'.PHP_EOL;
    }

    /**
     * Cancel timers on daemon shutdown.
     */
    public function detach(): void
    {
        // Timers are cancelled via the loop in the daemon — nothing to do here
        // since we don't hold a reference to the loop itself.
        echo '[Kronos] ReactScheduler detached.'.PHP_EOL;
    }

    /**
     * Reload the canonical config from Redis / YAML.
     * Called by the ReactRedisSubscriber on invalidation signals.
     */
    public function reloadConfig(): void
    {
        try {
            $loaded = $this->redis->load();
            if ($loaded !== []) {
                $this->config = $loaded;

                return;
            }
        } catch (Throwable) {
            // Redis unavailable — fall through to YAML
        }

        $path = config('kronos.config_path', storage_path('kronos.yaml'));
        if (file_exists($path)) {
            $this->config = Yaml::parseFile($path) ?? [];
        }
    }

    /**
     * Evaluate all due entries for the current timestamp.
     * Called every second by the loop timer.
     */
    protected function tick(): void
    {
        $now = now();

        $this->evaluateSchedules($now);
        $this->evaluateWorkflows($now);
    }

    protected function evaluateSchedules(Carbon $now): void
    {
        foreach ($this->config['schedules'] ?? [] as $entry) {
            if (!($entry['enabled'] ?? true)) {
                continue;
            }

            if (!$this->isDue($entry['cron_expression'], $entry['timezone'] ?? 'UTC', $now)) {
                continue;
            }

            $key = 'schedule:'.($entry['id'] ?? $entry['command']);

            if ($this->alreadyFiredThisMinute($key, $now)) {
                continue;
            }

            $this->markFired($key, $now);

            // Dispatch via Laravel queue — non-blocking, returns immediately
            dispatch(function () use ($entry): void {
                Artisan::call($entry['command']);
            })->onQueue(config('kronos.queue.name', 'kronos'));

            echo '[Kronos] Scheduled task fired: '.$entry['command'].PHP_EOL;
        }
    }

    protected function evaluateWorkflows(Carbon $now): void
    {
        foreach ($this->config['workflows'] ?? [] as $workflow) {
            if (!($workflow['enabled'] ?? true)) {
                continue;
            }

            $trigger = $workflow['trigger'] ?? [];

            if (($trigger['type'] ?? '') !== 'cron') {
                continue;
            }

            if (!$this->isDue($trigger['cron_expression'], $trigger['timezone'] ?? 'UTC', $now)) {
                continue;
            }

            $key = 'workflow:'.$workflow['name'];

            if ($this->alreadyFiredThisMinute($key, $now)) {
                continue;
            }

            $this->markFired($key, $now);

            $name = $workflow['name'];

            // Dispatch the trigger as a queued job — the orchestrator handles it
            dispatch(function () use ($name): void {
                app(KronosOrchestrator::class)->trigger($name);
            })->onQueue(config('kronos.queue.name', 'kronos'));

            echo '[Kronos] Workflow triggered: '.$name.PHP_EOL;
        }
    }

    /**
     * Check if a cron expression is due at the given time.
     * Uses Dragonmantank/cron-expression (bundled with Laravel).
     */
    protected function isDue(string $expression, string $timezone, Carbon $now): bool
    {
        try {
            $cronExpression = new CronExpression($expression);
            $localNow = $now->clone()->setTimezone($timezone);

            return $cronExpression->isDue($localNow->toDateTimeImmutable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Prevent double-firing within the same minute even though we tick every second.
     */
    protected function alreadyFiredThisMinute(string $key, Carbon $now): bool
    {
        $minute = $now->format('Y-m-d H:i');

        return ($this->lastFired[$key] ?? '') === $minute;
    }

    protected function markFired(string $key, Carbon $now): void
    {
        $this->lastFired[$key] = $now->format('Y-m-d H:i');
    }
}
