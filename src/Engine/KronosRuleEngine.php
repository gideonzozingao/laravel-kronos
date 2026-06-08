<?php

namespace ZuqongTech\Kronos\Engine;

use Illuminate\Database\Eloquent\Model;
use ZuqongTech\Kronos\Jobs\RebuildKronosConfig;
use ZuqongTech\Kronos\Rules\KronosRule;

class KronosRuleEngine
{
    /** @var KronosRule[] */
    protected array $rules = [];

    /**
     * Register a rule with the engine.
     */
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
                break; // One match is enough to trigger a rebuild
            }
        }

        if ($matched) {
            // ShouldBeUnique ensures rapid DB changes collapse into a single rebuild
            RebuildKronosConfig::dispatch();
        }
    }

    /**
     * Get all rules that match a given model class.
     */
    public function rulesForModel(string $modelClass): array
    {
        return array_filter(
            $this->rules,
            fn (KronosRule $rule) => $rule->getModelClass() === $modelClass
        );
    }

    /**
     * Get all registered rules.
     *
     * @return KronosRule[]
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * Build the full config payload from all rules + current DB state.
     */
    public function buildFullConfig(): array
    {
        $schedules = [];
        $workflows = [];

        foreach ($this->rules as $rule) {
            $modelClass = $rule->getModelClass();

            $modelClass::all()->each(function ($model) use ($rule, &$schedules, &$workflows) {
                if (!$rule->evaluate($model)) {
                    return;
                }

                $payload = $rule->produce($model);

                if (isset($payload['steps'])) {
                    $workflows[] = $payload;
                } else {
                    $schedules[] = $payload;
                }
            });
        }

        return compact('schedules', 'workflows');
    }
}