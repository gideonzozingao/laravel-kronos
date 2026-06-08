<?php

namespace ZuqongTech\Kronos\Engine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZuqongTech\Kronos\DAG\DAGResolver;
use ZuqongTech\Kronos\DAG\WorkflowContext;
use ZuqongTech\Kronos\Enums\RunStatus;
use ZuqongTech\Kronos\Enums\StepStatus;
use ZuqongTech\Kronos\Events\WorkflowCompleted;
use ZuqongTech\Kronos\Events\WorkflowFailed;
use ZuqongTech\Kronos\Events\WorkflowStepCompleted;
use ZuqongTech\Kronos\Events\WorkflowStepFailed;
use ZuqongTech\Kronos\Exceptions\KronosDeadlockException;
use ZuqongTech\Kronos\Jobs\ExecuteWorkflowStep;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;
use ZuqongTech\Kronos\Models\KronosStepRun;

class KronosOrchestrator
{
    public function __construct(protected DAGResolver $dag) {}

    /**
     * Create and begin a new workflow run.
     */
    public function trigger(string $workflowName, array $context = []): string
    {
        $workflow = KronosWorkflow::where('name', $workflowName)
            ->where('enabled', true)
            ->firstOrFail();

        $run = KronosWorkflowRun::create([
            'workflow_id' => $workflow->id,
            'run_id'      => Str::uuid(),
            'status'      => RunStatus::Pending,
            'context'     => $context,
            'started_at'  => now(),
        ]);

        // Seed step run records as pending
        foreach ($workflow->definition['steps'] ?? [] as $step) {
            KronosStepRun::create([
                'workflow_run_id' => $run->id,
                'step_name'       => $step['name'],
                'status'          => StepStatus::Pending,
                'attempt'         => 1,
            ]);
        }

        $run->update(['status' => RunStatus::Running]);

        $this->advance($run);

        return $run->run_id;
    }

    /**
     * Advance the workflow — dispatch all steps whose dependencies are met.
     * Protected by a distributed lock to prevent concurrent advancement.
     */
    public function advance(KronosWorkflowRun $run): void
    {
        $lock = Cache::lock("kronos:run:{$run->id}:advance", 30);

        $lock->block(10, function () use ($run) {
            if ($run->isTerminal()) {
                return;
            }

            $workflow = $run->workflow;
            $steps = $workflow->definition['steps'] ?? [];

            $completed = $run->stepRuns()
                ->where('status', StepStatus::Completed->value)
                ->pluck('step_name')
                ->toArray();

            $running = $run->stepRuns()
                ->where('status', StepStatus::Running->value)
                ->pluck('step_name')
                ->toArray();

            try {
                $ready = $this->dag->getReadySteps(
                    collect($steps)->keyBy('name')->toArray(),
                    $completed,
                    $running
                );
            } catch (KronosDeadlockException $e) {
                $run->update([
                    'status'      => RunStatus::Failed,
                    'finished_at' => now(),
                    'error'       => $e->getMessage(),
                ]);
                event(new WorkflowFailed($run, $e->getMessage()));
                return;
            }

            if (empty($ready) && empty($running)) {
                // No ready steps and nothing running — check if complete
                $pending = $run->stepRuns()
                    ->whereIn('status', [StepStatus::Pending->value, StepStatus::Skipped->value])
                    ->count();

                if ($pending === 0) {
                    $this->markComplete($run);
                } else {
                    $run->update([
                        'status'      => RunStatus::Failed,
                        'finished_at' => now(),
                        'error'       => 'Workflow stalled — no ready steps with pending steps remaining.',
                    ]);
                    event(new WorkflowFailed($run, 'Workflow stalled.'));
                }
                return;
            }

            foreach ($ready as $stepName) {
                $stepConfig = collect($steps)->firstWhere('name', $stepName);

                // Evaluate skip condition against context
                if ($condition = $stepConfig['condition'] ?? null) {
                    $ctx = new WorkflowContext($run);
                    if (!$ctx->get($condition)) {
                        $run->stepRuns()
                            ->where('step_name', $stepName)
                            ->update(['status' => StepStatus::Skipped->value]);
                        continue;
                    }
                }

                // Mark as running before dispatch to prevent double-dispatch
                $run->stepRuns()
                    ->where('step_name', $stepName)
                    ->update([
                        'status'     => StepStatus::Running->value,
                        'started_at' => now(),
                    ]);

                dispatch(new ExecuteWorkflowStep($run->id, $stepName));
            }
        });
    }

    /**
     * Called by ExecuteWorkflowStep on success.
     */
    public function onStepCompleted(KronosWorkflowRun $run, string $stepName, array $output = []): void
    {
        $run->stepRuns()
            ->where('step_name', $stepName)
            ->update([
                'status'      => StepStatus::Completed->value,
                'output'      => $output,
                'finished_at' => now(),
            ]);

        event(new WorkflowStepCompleted($run, $stepName));

        $this->advance($run);
    }

    /**
     * Called by ExecuteWorkflowStep on failure.
     */
    public function onStepFailed(
        KronosWorkflowRun $run,
        string $stepName,
        string $error,
        bool $willRetry = false
    ): void {
        if ($willRetry) {
            $run->stepRuns()
                ->where('step_name', $stepName)
                ->increment('attempt');
            return;
        }

        $run->stepRuns()
            ->where('step_name', $stepName)
            ->update([
                'status'      => StepStatus::Failed->value,
                'exception'   => $error,
                'finished_at' => now(),
            ]);

        event(new WorkflowStepFailed($run, $stepName, $error));

        $workflow = $run->workflow;

        if ($workflow->definition['stop_on_failure'] ?? true) {
            $run->update([
                'status'      => RunStatus::Failed,
                'finished_at' => now(),
                'error'       => "Step [{$stepName}] failed: {$error}",
            ]);
            event(new WorkflowFailed($run, "Step [{$stepName}] failed."));
        } else {
            // Skip remaining dependents, continue rest
            $this->advance($run);
        }
    }

    protected function markComplete(KronosWorkflowRun $run): void
    {
        $run->update([
            'status'      => RunStatus::Completed,
            'finished_at' => now(),
        ]);

        event(new WorkflowCompleted($run));

        // Check if any other workflow is waiting on this one
        $this->triggerDependentWorkflows($run->workflow->name);
    }

    protected function triggerDependentWorkflows(string $completedWorkflowName): void
    {
        KronosWorkflow::where('enabled', true)
            ->whereJsonContains('definition->trigger->after_workflow', $completedWorkflowName)
            ->each(fn ($workflow) => $this->trigger($workflow->name));
    }
}