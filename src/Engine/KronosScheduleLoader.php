<?php

namespace ZuqongTech\Kronos\Engine;

use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Yaml\Yaml;
use ZuqongTech\Kronos\Writers\RedisConfigStore;

class KronosScheduleLoader
{
    public function __construct(protected RedisConfigStore $redis) {}

    /**
     * Load all schedules and workflows from the canonical config
     * and hydrate Laravel's scheduler.
     */
    public function hydrate(Schedule $schedule): void
    {
        $config = $this->loadConfig();

        $this->hydrateSchedules($schedule, $config['schedules'] ?? []);
        $this->hydrateWorkflows($schedule, $config['workflows'] ?? []);
    }

    protected function hydrateSchedules(Schedule $schedule, array $schedules): void
    {
        foreach ($schedules as $entry) {
            if (!($entry['enabled'] ?? true)) {
                continue;
            }

            $event = $schedule->command($entry['command'])
                ->cron($entry['cron_expression'])
                ->timezone($entry['timezone'] ?? config('app.timezone', 'UTC'));

            if ($entry['without_overlapping'] ?? false) {
                $event->withoutOverlapping($entry['overlap_ttl'] ?? 1440);
            }

            if ($entry['on_one_server'] ?? config('kronos.multi_node', false)) {
                $event->onOneServer();
            }

            if ($entry['run_in_background'] ?? false) {
                $event->runInBackground();
            }

            if ($entry['on_failure_webhook'] ?? null) {
                $webhook = $entry['on_failure_webhook'];
                $event->onFailure(fn () => rescue(fn () => \Http::post($webhook, ['entry' => $entry])));
            }
        }
    }

    protected function hydrateWorkflows(Schedule $schedule, array $workflows): void
    {
        foreach ($workflows as $workflow) {
            if (!($workflow['enabled'] ?? true)) {
                continue;
            }

            $trigger = $workflow['trigger'] ?? [];

            if (($trigger['type'] ?? '') !== 'cron') {
                continue;
            }

            $event = $schedule->call(function () use ($workflow) {
                app(\ZuqongTech\Kronos\Engine\KronosOrchestrator::class)
                    ->trigger($workflow['name']);
            })
            ->cron($trigger['cron_expression'])
            ->timezone($trigger['timezone'] ?? 'UTC')
            ->name("kronos:workflow:{$workflow['name']}");

            if (config('kronos.multi_node', false)) {
                $event->onOneServer();
            }
        }
    }

    /**
     * Load the canonical config — prefers Redis, falls back to YAML file.
     */
    protected function loadConfig(): array
    {
        // Try Redis first (multi-node canonical store)
        $fromRedis = $this->redis->load();
        if (!empty($fromRedis)) {
            return $fromRedis;
        }

        // Fall back to YAML file
        $path = config('kronos.config_path', storage_path('kronos.yaml'));
        if (!file_exists($path)) {
            return [];
        }

        return Yaml::parseFile($path) ?? [];
    }
}