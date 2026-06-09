<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\Observers;

use Illuminate\Database\Eloquent\Model;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;

class KronosModelObserver
{
    public function __construct(protected KronosRuleEngine $engine) {}

    public function created(Model $model): void
    {
        $this->engine->evaluate($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->engine->evaluate($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->engine->evaluate($model, 'deleted');
    }
}
