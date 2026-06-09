<?php

namespace ZuqongTech\Kronos\Facades;

use Illuminate\Support\Facades\Facade;
use ZuqongTech\Kronos\DAG\WorkflowDefinition;
use ZuqongTech\Kronos\Rules\KronosRule;

/**
 * @method static KronosRule rule(string $name)
 * @method static WorkflowDefinition workflow(string $name)
 * @method static string trigger(string $workflowName, array $context = [])
 * @method static void rebuild()
 *
 * @see \ZuqongTech\Kronos\Kronos
 */
class Kronos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'kronos';
    }
}
