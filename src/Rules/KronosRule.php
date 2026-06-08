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
    protected array $andConditions = [];
    protected array $watchEvents = ['created', 'updated', 'deleted'];

    public function __construct(
        public readonly string $name,
        protected KronosRuleEngine $engine,
        protected KronosConfigWriter $writer,
    ) {}

    /**
     * Watch a model class and evaluate a condition on its events.
     */
    public function when(string $modelClass, Closure $condition): static
    {
        $this->modelClass = $modelClass;
        $this->condition = $condition;

        // Register observer on the model dynamically
        $modelClass::observe(new KronosModelObserver($this->engine));

        return $this;
    }

    /**
     * Add an additional cross-model condition that must also be true.
     */
    public function andWhen(string $modelClass, Closure $condition): static
    {
        $this->andConditions[] = [
            'model' => $modelClass,
            'condition' => $condition,
        ];
        return $this;
    }

    /**
     * Only watch specific model events.
     */
    public function onEvents(array $events): static
    {
        $this->watchEvents = $events;
        return $this;
    }

    /**
     * Define the schedule/workflow config this rule produces when matched.
     */
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

        // Evaluate any cross-model AND conditions
        foreach ($this->andConditions as $andCondition) {
            $related = app($andCondition['model'])->first();
            if (!$related || !($andCondition['condition'])($related)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute the producer to get the config payload.
     */
    public function produce(Model $model): array
    {
        return ($this->producer)($model);
    }

    /**
     * Get the model class this rule watches.
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get the watched events.
     */
    public function getWatchEvents(): array
    {
        return $this->watchEvents;
    }
}