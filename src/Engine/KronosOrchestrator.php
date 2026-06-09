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
use ZuqongTech\Kronos\ReactPHP\Broadcast\RunBroadcaster;

class KronosOrchestrator
{
    public function __construct(
        protected DAGResolver $dag,
        protected ?RunBroadcaster $broadcaster = null,
    ) {}

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
            'run_id' => Str::uuid()->toString(),
            'status' => RunStatus::Pending,
            'context' => $context,
            'started_at' => now(),
        ]);

        $run->setRelation('workflow', $workflow);

        foreach ($workflow->definition['steps'] ?? [] as $step) {
            KronosStepRun::create([
                'workflow_run_id' => $run->id,
                'step_name' => $step['name'],
                'status' => StepStatus::Pending,
                'attempt' => 1,
            ]);
        }

        $run->update(['status' => RunStatus::Running]);

        // Broadcast workflow start to WebSocket clients
        $this->broadcaster?->workflowUpdated($run, RunStatus::Running->value);

        $this->advance($run);

        return $run->run_id;
    }

    /**
     * Advance the workflow — dispatch all steps whose dependencies are met.
     * Protected by a distributed Redis lock.
     */
    public function advance(KronosWorkflowRun $kronosWorkflowRun): void
    {
        $lock = Cache::lock(sprintf('kronos:run:%s:advance', $kronosWorkflowRun->id), 30);

        $lock->block(10, function () use ($kronosWorkflowRun): void {
            $kronosWorkflowRun->refresh();

            if ($kronosWorkflowRun->isTerminal()) {
                return;
            }

            $workflow = $kronosWorkflowRun->workflow;
            $steps = $workflow->definition['steps'] ?? [];

            $completed = $kronosWorkflowRun->stepRuns()
                ->whereIn('status', [StepStatus::Completed->value, StepStatus::Skipped->value])
                ->pluck('step_name')
                ->toArray();

            $running = $kronosWorkflowRun->stepRuns()
                ->where('status', StepStatus::Running->value)
                ->pluck('step_name')
                ->toArray();

            try {
                $ready = $this->dag->getReadySteps(
                    collect($steps)->keyBy('name')->toArray(),
                    $completed,
                    $running,
                );
            } catch (KronosDeadlockException $kronosDeadlockException) {
                $kronosWorkflowRun->update([
                    'status' => RunStatus::Failed,
                    'finished_at' => now(),
                    'error' => $kronosDeadlockException->getMessage(),
                ]);
                event(new WorkflowFailed($kronosWorkflowRun, $kronosDeadlockException->getMessage()));
                $this->broadcaster?->workflowUpdated($kronosWorkflowRun, RunStatus::Failed->value, [
                    'error' => $kronosDeadlockException->getMessage(),
                ]);

                return;
            }

            if ($ready === [] && empty($running)) {
                $pending = $kronosWorkflowRun->stepRuns()
                    ->where('status', StepStatus::Pending->value)
                    ->count();

                if ($pending === 0) {
                    $this->markComplete($kronosWorkflowRun);
                } else {
                    $error = 'Workflow stalled — no ready steps with pending steps remaining.';
                    $kronosWorkflowRun->update([
                        'status' => RunStatus::Failed,
                        'finished_at' => now(),
                        'error' => $error,
                    ]);
                    event(new WorkflowFailed($kronosWorkflowRun, 'Workflow stalled.'));
                    $this->broadcaster?->workflowUpdated($kronosWorkflowRun, RunStatus::Failed->value, [
                        'error' => $error,
                    ]);
                }

                return;
            }

            $stepIndex = collect($steps)->keyBy('name');

            foreach ($ready as $stepName) {
                $stepConfig = $stepIndex->get($stepName);

                if ($condition = ($stepConfig['condition'] ?? null)) {
                    $ctx = new WorkflowContext($kronosWorkflowRun);
                    if (!$ctx->get($condition)) {
                        $kronosWorkflowRun->stepRuns()
                            ->where('step_name', $stepName)
                            ->update(['status' => StepStatus::Skipped->value]);
                        $this->broadcaster?->stepUpdated($kronosWorkflowRun, $stepName, StepStatus::Skipped->value);

                        continue;
                    }
                }

                $kronosWorkflowRun->stepRuns()
                    ->where('step_name', $stepName)
                    ->update([
                        'status' => StepStatus::Running->value,
                        'started_at' => now(),
                    ]);

                $this->broadcaster?->stepUpdated($kronosWorkflowRun, $stepName, StepStatus::Running->value);

                dispatch(new ExecuteWorkflowStep(
                    runId: $kronosWorkflowRun->id,
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
    public function onStepCompleted(KronosWorkflowRun $kronosWorkflowRun, string $stepName, array $output = []): void
    {
        $kronosWorkflowRun->stepRuns()
            ->where('step_name', $stepName)
            ->update([
                'status' => StepStatus::Completed->value,
                'output' => $output,
                'finished_at' => now(),
            ]);

        event(new WorkflowStepCompleted($kronosWorkflowRun, $stepName));
        $this->broadcaster?->stepUpdated($kronosWorkflowRun, $stepName, StepStatus::Completed->value, [
            'output' => $output,
        ]);

        $this->advance($kronosWorkflowRun);
    }

    /**
     * Called by ExecuteWorkflowStep on failure.
     */
    public function onStepFailed(
        KronosWorkflowRun $kronosWorkflowRun,
        string $stepName,
        string $error,
        bool $willRetry = false,
    ): void {
        if ($willRetry) {
            $kronosWorkflowRun->stepRuns()
                ->where('step_name', $stepName)
                ->increment('attempt');
            $this->broadcaster?->stepUpdated($kronosWorkflowRun, $stepName, 'retrying', ['error' => $error]);

            return;
        }

        $kronosWorkflowRun->stepRuns()
            ->where('step_name', $stepName)
            ->update([
                'status' => StepStatus::Failed->value,
                'exception' => $error,
                'finished_at' => now(),
            ]);

        event(new WorkflowStepFailed($kronosWorkflowRun, $stepName, $error));
        $this->broadcaster?->stepUpdated($kronosWorkflowRun, $stepName, StepStatus::Failed->value, [
            'error' => $error,
        ]);

        $workflow = $kronosWorkflowRun->workflow;

        if ($workflow->definition['stop_on_failure'] ?? true) {
            $kronosWorkflowRun->update([
                'status' => RunStatus::Failed,
                'finished_at' => now(),
                'error' => sprintf('Step [%s] failed: %s', $stepName, $error),
            ]);
            event(new WorkflowFailed($kronosWorkflowRun, sprintf('Step [%s] failed.', $stepName)));
            $this->broadcaster?->workflowUpdated($kronosWorkflowRun, RunStatus::Failed->value, [
                'failed_step' => $stepName,
            ]);
        } else {
            $this->advance($kronosWorkflowRun);
        }
    }

    /**
     * Cancel a running workflow run.
     */
    public function cancel(KronosWorkflowRun $kronosWorkflowRun, string $reason = 'Cancelled by operator'): void
    {
        if ($kronosWorkflowRun->isTerminal()) {
            return;
        }

        $kronosWorkflowRun->update([
            'status' => RunStatus::Cancelled,
            'finished_at' => now(),
            'error' => $reason,
        ]);

        $kronosWorkflowRun->stepRuns()
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->update(['status' => StepStatus::Skipped->value]);

        $this->broadcaster?->workflowUpdated($kronosWorkflowRun, RunStatus::Cancelled->value, [
            'reason' => $reason,
        ]);
    }

    protected function markComplete(KronosWorkflowRun $kronosWorkflowRun): void
    {
        $kronosWorkflowRun->update([
            'status' => RunStatus::Completed,
            'finished_at' => now(),
        ]);

        event(new WorkflowCompleted($kronosWorkflowRun));
        $this->broadcaster?->workflowUpdated($kronosWorkflowRun, RunStatus::Completed->value);

        $this->triggerDependentWorkflows($kronosWorkflowRun->workflow->name);
    }

    protected function triggerDependentWorkflows(string $completedWorkflowName): void
    {
        KronosWorkflow::where('enabled', true)
            ->whereRaw(
                "JSON_EXTRACT(definition, '$.trigger.after_workflow') = ?",
                [$completedWorkflowName],
            )
            ->each(fn ($workflow): string => $this->trigger($workflow->name));
    }
}
