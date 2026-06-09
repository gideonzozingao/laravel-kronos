<?php

namespace ZuqongTech\Kronos\DAG;

use Closure;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Jobs\RebuildKronosConfig;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

class WorkflowDefinition
{
    protected TriggerDefinition $trigger;

    protected array $steps = [];

    protected array $branches = [];

    protected ?Closure $onFailure = null;

    protected ?Closure $onSuccess = null;

    protected bool $stopOnFailure = true;

    public function __construct(
        public readonly string $name,
        protected KronosOrchestrator $orchestrator,
        protected KronosConfigWriter $writer,
    ) {
        $this->trigger = new TriggerDefinition($this);
    }

    /** Define the trigger for this workflow. */
    public function trigger(): TriggerDefinition
    {
        return $this->trigger;
    }

    /** Add a step to the workflow. */
    public function step(string $name): StepDefinition
    {
        $stepDefinition = new StepDefinition($name, $this);
        $this->steps[$name] = $stepDefinition;

        return $stepDefinition;
    }

    /** Add parallel steps — all run concurrently, next step waits for all. */
    public function parallel(StepDefinition ...$steps): static
    {
        foreach ($steps as $step) {
            $this->steps[$step->getName()] = $step->parallel(true);
        }

        return $this;
    }

    /** Open a conditional branch. */
    public function branch(): BranchDefinition
    {
        $branchDefinition = new BranchDefinition($this);
        $this->branches[] = $branchDefinition;

        return $branchDefinition;
    }

    /** Set callback for workflow-level failure. */
    public function onFailure(Closure $callback): static
    {
        $this->onFailure = $callback;

        return $this;
    }

    /** Set callback for workflow-level success. */
    public function onSuccess(Closure $callback): static
    {
        $this->onSuccess = $callback;

        return $this;
    }

    /** Whether to halt the entire workflow on any step failure. */
    public function continueOnFailure(): static
    {
        $this->stopOnFailure = false;

        return $this;
    }

    /**
     * Persist the workflow definition to DB then dispatch an async config rebuild.
     *
     * Fix #3: previously called rebuildFromDatabase() synchronously during boot,
     * blocking the request. Now dispatches the unique debounced queue job instead.
     */
    public function register(): static
    {
        KronosWorkflow::updateOrCreate(
            ['name' => $this->name],
            [
                'trigger_type' => $this->trigger->getType(),
                'cron_expression' => $this->trigger->getCron(),
                'timezone' => $this->trigger->getTimezone(),
                'definition' => $this->toArray(),
                'enabled' => true,
            ],
        );

        // Async — does not block the booting service provider
        RebuildKronosConfig::dispatch();

        return $this;
    }

    /** Serialize the full DAG to an array (for DB + YAML storage). */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'stop_on_failure' => $this->stopOnFailure,
            'trigger' => $this->trigger->toArray(),
            'steps' => array_map(
                fn (StepDefinition $stepDefinition): array => $stepDefinition->toArray(),
                $this->steps,
            ),
            'branches' => array_map(
                fn (BranchDefinition $branchDefinition): array => $branchDefinition->toArray(),
                $this->branches,
            ),
        ];
    }

    /** @return StepDefinition[] */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getOnFailure(): ?Closure
    {
        return $this->onFailure;
    }

    public function getOnSuccess(): ?Closure
    {
        return $this->onSuccess;
    }

    public function shouldStopOnFailure(): bool
    {
        return $this->stopOnFailure;
    }
}
