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
     * Rebuild the full config from current DB state and write to YAML + Redis.
     * This is always called via the unique queue job — never inline.
     */
    public function rebuildFromDatabase(): void
    {
        $lock = Cache::lock('kronos:config:write', 15);

        $lock->block(10, function (): void {
            $ruleConfig = $this->ruleEngine->buildFullConfig();

            $workflows = KronosWorkflow::where('enabled', true)
                ->get()
                ->map(fn ($wf): array => array_merge($wf->definition, [
                    'id' => $wf->id,
                    'name' => $wf->name,
                    'enabled' => $wf->enabled,
                ]))
                ->toArray();

            $payload = [
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'schedules' => $ruleConfig['schedules'],
                'workflows' => array_merge($ruleConfig['workflows'], $workflows),
            ];

            $this->writeYaml($payload);
            $this->redis->store($payload);
            $this->redis->broadcastInvalidation();
        });
    }

    /**
     * Additive upsert of a single schedule entry.
     *
     * Fix #11: broadcastInvalidation() added so other nodes are notified.
     */
    public function writeScheduleEntry(array $entry): void
    {
        $config = $this->loadCurrent();
        $config['schedules'] ??= [];

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
        $this->redis->broadcastInvalidation(); // Fix #11
    }

    /**
     * Remove a schedule entry by ID.
     *
     * Fix #11: broadcastInvalidation() added.
     */
    public function removeScheduleEntry(int|string $id): void
    {
        $config = $this->loadCurrent();

        $config['schedules'] = collect($config['schedules'] ?? [])
            ->reject(fn ($s): bool => ($s['id'] ?? null) == $id)
            ->values()
            ->toArray();

        $config['generated_at'] = now()->toIso8601String();

        $this->writeYaml($config);
        $this->redis->store($config);
        $this->redis->broadcastInvalidation(); // Fix #11
    }

    /**
     * Atomic YAML write — write to .tmp then rename() to prevent partial reads.
     */
    protected function writeYaml(array $payload): void
    {
        $path = config('kronos.config_path', storage_path('kronos.yaml'));
        $tmp = $path.'.tmp.'.getmypid();

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
