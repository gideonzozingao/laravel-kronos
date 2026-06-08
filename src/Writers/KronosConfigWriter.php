<?php

namespace ZuqongTech\Kronos\Writers;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Models\KronosWorkflow;

class KronosConfigWriter
{
    public function __construct(
        protected KronosRuleEngine $ruleEngine,
        protected RedisConfigStore $redis,
    ) {}

    /**
     * Rebuild the full config from the current DB state and write everywhere.
     * This is the single write point — always called via the unique queue job.
     */
    public function rebuildFromDatabase(): void
    {
        $lock = Cache::lock('kronos:config:write', 15);

        $lock->block(10, function () {
            // 1. Get schedule entries from rule engine
            $ruleConfig = $this->ruleEngine->buildFullConfig();

            // 2. Get workflow definitions from DB
            $workflows = KronosWorkflow::where('enabled', true)
                ->get()
                ->map(fn ($wf) => array_merge(
                    $wf->definition,
                    [
                        'id'      => $wf->id,
                        'name'    => $wf->name,
                        'enabled' => $wf->enabled,
                    ]
                ))
                ->toArray();

            $payload = [
                'version'      => 1,
                'generated_at' => now()->toIso8601String(),
                'schedules'    => $ruleConfig['schedules'],
                'workflows'    => array_merge($ruleConfig['workflows'], $workflows),
            ];

            // 3. Write to YAML file (atomically)
            $this->writeYaml($payload);

            // 4. Write to Redis (for multi-node sync)
            $this->redis->store($payload);

            // 5. Broadcast cache invalidation to all nodes
            $this->redis->broadcastInvalidation();
        });
    }

    /**
     * Write a single schedule entry (additive, no full rebuild).
     */
    public function writeScheduleEntry(array $entry): void
    {
        $config = $this->loadCurrent();
        $config['schedules'] ??= [];

        // Upsert by ID
        $existing = collect($config['schedules'])->firstWhere('id', $entry['id'] ?? null);
        if ($existing) {
            $config['schedules'] = collect($config['schedules'])
                ->map(fn ($s) => ($s['id'] ?? null) === $entry['id'] ? $entry : $s)
                ->toArray();
        } else {
            $config['schedules'][] = $entry;
        }

        $config['generated_at'] = now()->toIso8601String();

        $this->writeYaml($config);
        $this->redis->store($config);
    }

    /**
     * Remove a schedule entry by ID.
     */
    public function removeScheduleEntry(int|string $id): void
    {
        $config = $this->loadCurrent();
        $config['schedules'] = collect($config['schedules'] ?? [])
            ->reject(fn ($s) => ($s['id'] ?? null) == $id)
            ->values()
            ->toArray();

        $config['generated_at'] = now()->toIso8601String();

        $this->writeYaml($config);
        $this->redis->store($config);
    }

    /**
     * Atomic YAML write — writes to temp file then renames (prevents partial reads).
     */
    protected function writeYaml(array $payload): void
    {
        $path = config('kronos.config_path', storage_path('kronos.yaml'));
        $tmp = $path . '.tmp.' . getmypid();

        file_put_contents($tmp, Yaml::dump($payload, 4, 2));
        rename($tmp, $path);
    }

    protected function loadCurrent(): array
    {
        $path = config('kronos.config_path', storage_path('kronos.yaml'));

        if (!file_exists($path)) {
            return ['version' => 1, 'schedules' => [], 'workflows' => []];
        }

        return Yaml::parseFile($path) ?? [];
    }
}