<?php

namespace ZuqongTech\Kronos\Rules;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Observers\KronosModelObserver;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

class KronosRule
{
    protected string $modelClass;

    protected Closure $condition;

    protected Closure $producer;

    protected array $watchEvents = ['created', 'updated', 'deleted'];

    /**
     * Fix #4: andConditions now stores [model => string, resolver => Closure]
     * where resolver receives the primary model and returns the related model
     * (or null), avoiding the unsafe app($modelClass)->first() full-table scan.
     *
     * Usage:
     *   ->andWhen(CompanySettings::class,
     *       resolver: fn($task) => CompanySettings::find($task->company_id),
     *       condition: fn($settings) => $settings->subscription_active,
     *   )
     */
    protected array $andConditions = [];

    public function __construct(
        public readonly string $name,
        protected KronosRuleEngine $engine,
        protected KronosConfigWriter $writer,
    ) {}

    /**
     * Watch a model class and evaluate a condition on its events.
     *
     * Fix #20: observer resolved via container, not instantiated with `new`.
     */
    public function when(string $modelClass, Closure $condition): static
    {
        $this->modelClass = $modelClass;
        $this->condition = $condition;

        $modelClass::observe(app(KronosModelObserver::class));

        return $this;
    }

    /**
     * Add a cross-model AND condition.
     *
     * @param  string  $modelClass  The related model class.
     * @param  Closure  $resolver  Receives the primary model, returns related model or null.
     * @param  Closure  $condition  Receives the related model, returns bool.
     */
    public function andWhen(string $modelClass, Closure $resolver, Closure $condition): static
    {
        $this->andConditions[] = [
            'model' => $modelClass,
            'resolver' => $resolver,
            'condition' => $condition,
        ];

        return $this;
    }

    /** Restrict which model events trigger evaluation. */
    public function onEvents(array $events): static
    {
        $this->watchEvents = $events;

        return $this;
    }

    /** Define the config entry this rule produces when matched. */
    public function produces(Closure $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    /**
     * Evaluate whether this rule applies to a given model instance.
     */
    public function evaluate(Model $model): bool
    {
        if (!$model instanceof $this->modelClass) {
            return false;
        }

        if (!($this->condition)($model)) {
            return false;
        }

        // Fix #4: use consumer-supplied resolver instead of ->first() full scan
        foreach ($this->andConditions as $andCondition) {
            $related = ($andCondition['resolver'])($model);
            if (!$related || !($andCondition['condition'])($related)) {
                return false;
            }
        }

        return true;
    }

    /** Execute the producer to get the config payload. */
    public function produce(Model $model): array
    {
        return ($this->producer)($model);
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function getWatchEvents(): array
    {
        return $this->watchEvents;
    }
}
