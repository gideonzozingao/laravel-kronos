<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos;

use ZuqongTech\Kronos\DAG\WorkflowDefinition;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Engine\KronosRuleEngine;
use ZuqongTech\Kronos\Rules\KronosRule;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

class Kronos
{
    public function __construct(
        protected KronosRuleEngine $ruleEngine,
        protected KronosOrchestrator $orchestrator,
        protected KronosConfigWriter $writer,
    ) {}

    /**
     * Define a new rule that maps a model event to a schedule/workflow config write.
     *
     * Usage:
     *   Kronos::rule('activate_report')
     *       ->when(ScheduledTask::class, fn($task) => $task->is_enabled)
     *       ->produces(fn($task) => [...]);
     */
    public function rule(string $name): KronosRule
    {
        $kronosRule = new KronosRule($name, $this->ruleEngine, $this->writer);
        $this->ruleEngine->register($kronosRule);

        return $kronosRule;
    }

    /**
     * Define a new workflow with a fluent DAG builder.
     *
     * Usage:
     *   Kronos::workflow('monthly_payroll')
     *       ->trigger()->cron('0 0 1 * *')
     *       ->step('validate')->run(ValidateJob::class)
     *       ->register();
     */
    public function workflow(string $name): WorkflowDefinition
    {
        return new WorkflowDefinition($name, $this->orchestrator, $this->writer);
    }

    /**
     * Manually trigger a workflow by name.
     */
    public function trigger(string $workflowName, array $context = []): string
    {
        return $this->orchestrator->trigger($workflowName, $context);
    }

    /**
     * Get the rule engine instance.
     */
    public function rules(): KronosRuleEngine
    {
        return $this->ruleEngine;
    }

    /**
     * Get the orchestrator instance.
     */
    public function orchestrator(): KronosOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * Rebuild the full kronos.yaml / Redis config from current DB state.
     */
    public function rebuild(): void
    {
        $this->writer->rebuildFromDatabase();
    }
}
