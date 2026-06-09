<?php

namespace ZuqongTech\Kronos\Engine;

use Illuminate\Database\Eloquent\Model;
use ZuqongTech\Kronos\Jobs\RebuildKronosConfig;
use ZuqongTech\Kronos\Rules\KronosRule;

class KronosRuleEngine
{
    /** @var KronosRule[] */
    protected array $rules = [];

    /** Register a rule with the engine. */
    public function register(KronosRule $rule): void
    {
        $this->rules[$rule->name] = $rule;
    }

    /**
     * Evaluate all rules against a model event.
     * Dispatches a unique debounced rebuild job when any rule matches.
     */
    public function evaluate(Model $model, string $event): void
    {
        $matched = false;

        foreach ($this->rules as $rule) {
            if (!in_array($event, $rule->getWatchEvents())) {
                continue;
            }
            if ($rule->evaluate($model)) {
                $matched = true;
                break;
            }
        }

        if ($matched) {
            RebuildKronosConfig::dispatch();
        }
    }

    /** @return KronosRule[] */
    public function rulesForModel(string $modelClass): array
    {
        return array_filter(
            $this->rules,
            fn (KronosRule $rule) => $rule->getModelClass() === $modelClass,
        );
    }

    /** @return KronosRule[] */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * Build the full config payload from all rules + current DB state.
     *
     * Fix #7: uses chunk() instead of ::all() to prevent full table scans
     * on large watched tables.
     */
    public function buildFullConfig(): array
    {
        $schedules = [];
        $workflows = [];

        foreach ($this->rules as $rule) {
            $modelClass = $rule->getModelClass();

            $modelClass::chunk(200, function ($models) use ($rule, &$schedules, &$workflows): void {
                foreach ($models as $model) {
                    if (!$rule->evaluate($model)) {
                        continue;
                    }

                    $payload = $rule->produce($model);

                    if (isset($payload['steps'])) {
                        $workflows[] = $payload;
                    } else {
                        $schedules[] = $payload;
                    }
                }
            });
        }

        return compact('schedules', 'workflows');
    }
}
