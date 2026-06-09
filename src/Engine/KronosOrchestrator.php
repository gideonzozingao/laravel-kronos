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
use ZuqongTech\Kronos\Models\KronosStepRun;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

class KronosOrchestrator
{
    public function __construct(protected DAGResolver $dag) {}

    /**
     * Create and begin a new workflow run.
     */
    public function trigger(string $workflowName, array $context = []): string
    {
        // Fix #18: eager-load workflow once at entry point
        $workflow = KronosWorkflow::where('name', $workflowName)
            ->where('enabled', true)
            ->firstOrFail();

        $run = KronosWorkflowRun::create([
            'workflow_id' => $workflow->id,
            'run_id' => Str::uuid()->toString(),
            'status' => RunStatus::Pending,
            'context' => $context,
            'started_at' => now(),
        ]);

        // Associate the already-loaded workflow to avoid a re-query
        $run->setRelation('workflow', $workflow);

        // Seed step records as pending
        foreach ($workflow->definition['steps'] ?? [] as $step) {
            KronosStepRun::create([
                'workflow_run_id' => $run->id,
                'step_name' => $step['name'],
                'status' => StepStatus::Pending,
                'attempt' => 1,
            ]);
        }

        $run->update(['status' => RunStatus::Running]);

        $this->advance($run);

        return $run->run_id;
    }

    /**
     * Advance the workflow — dispatch all steps whose dependencies are met.
     * Protected by a distributed Redis lock to prevent concurrent advancement.
     *
     * Fix #6: $run is refreshed inside the lock to prevent stale in-memory state.
     */
    public function advance(KronosWorkflowRun $run): void
    {
        $lock = Cache::lock("kronos:run:{$run->id}:advance", 30);

        $lock->block(10, function () use ($run): void {
            // Fix #6: refresh inside the lock — another process may have mutated this
            $run->refresh();

            if ($run->isTerminal()) {
                return;
            }

            // Fix #18: load workflow once, reuse throughout this call
            $workflow = $run->workflow;
            $steps = $workflow->definition['steps'] ?? [];

            $completed = $run->stepRuns()
                ->whereIn('status', [StepStatus::Completed->value, StepStatus::Skipped->value])
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
                    $running,
                );
            } catch (KronosDeadlockException $e) {
                $run->update([
                    'status' => RunStatus::Failed,
                    'finished_at' => now(),
                    'error' => $e->getMessage(),
                ]);
                event(new WorkflowFailed($run, $e->getMessage()));

                return;
            }

            if (empty($ready) && empty($running)) {
                $pending = $run->stepRuns()
                    ->where('status', StepStatus::Pending->value)
                    ->count();

                if ($pending === 0) {
                    $this->markComplete($run);
                } else {
                    $run->update([
                        'status' => RunStatus::Failed,
                        'finished_at' => now(),
                        'error' => 'Workflow stalled — no ready steps with pending steps remaining.',
                    ]);
                    event(new WorkflowFailed($run, 'Workflow stalled.'));
                }

                return;
            }

            $stepIndex = collect($steps)->keyBy('name');

            foreach ($ready as $stepName) {
                $stepConfig = $stepIndex->get($stepName);

                // Evaluate skip condition against context
                if ($condition = ($stepConfig['condition'] ?? null)) {
                    $ctx = new WorkflowContext($run);
                    if (!$ctx->get($condition)) {
                        $run->stepRuns()
                            ->where('step_name', $stepName)
                            ->update(['status' => StepStatus::Skipped->value]);

                        continue;
                    }
                }

                // Mark running before dispatch — prevents double-dispatch
                $run->stepRuns()
                    ->where('step_name', $stepName)
                    ->update([
                        'status' => StepStatus::Running->value,
                        'started_at' => now(),
                    ]);

                // Fix #2: pass tries/backoff/timeout at dispatch time
                dispatch(new ExecuteWorkflowStep(
                    runId: $run->id,
                    stepName: $stepName,
                    tries: $stepConfig['retries'] ?? 1,
                    backoff: $stepConfig['retry_delay'] ?? 60,
                    timeout: $stepConfig['timeout'] ?? 3600,
                ));
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
                'status' => StepStatus::Completed->value,
                'output' => $output,
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
        bool $willRetry = false,
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
                'status' => StepStatus::Failed->value,
                'exception' => $error,
                'finished_at' => now(),
            ]);

        event(new WorkflowStepFailed($run, $stepName, $error));

        // Fix #18: load workflow once via local var
        $workflow = $run->workflow;

        if ($workflow->definition['stop_on_failure'] ?? true) {
            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'error' => "Step [{$stepName}] failed: {$error}",
            ]);
            event(new WorkflowFailed($run, "Step [{$stepName}] failed."));
        } else {
            $this->advance($run);
        }
    }

    /**
     * Cancel a running workflow run.
     * Fix #23: cancellation support.
     */
    public function cancel(KronosWorkflowRun $run, string $reason = 'Cancelled by operator'): void
    {
        if ($run->isTerminal()) {
            return;
        }

        $run->update([
            'status' => RunStatus::Cancelled,
            'finished_at' => now(),
            'error' => $reason,
        ]);

        // Mark any pending/running steps as skipped
        $run->stepRuns()
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->update(['status' => StepStatus::Skipped->value]);
    }

    protected function markComplete(KronosWorkflowRun $run): void
    {
        $run->update([
            'status' => RunStatus::Completed,
            'finished_at' => now(),
        ]);

        event(new WorkflowCompleted($run));

        $this->triggerDependentWorkflows($run->workflow->name);
    }

    /**
     * Fix #8: store after_workflow as a plain string scalar in TriggerDefinition,
     * so query here uses a JSON value match instead of whereJsonContains (array).
     */
    protected function triggerDependentWorkflows(string $completedWorkflowName): void
    {
        KronosWorkflow::where('enabled', true)
            ->whereRaw(
                "JSON_EXTRACT(definition, '$.trigger.after_workflow') = ?",
                [$completedWorkflowName],
            )
            ->each(fn ($workflow) => $this->trigger($workflow->name));
    }
}
