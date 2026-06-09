<?php

namespace ZuqongTech\Kronos\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use ZuqongTech\Kronos\Contracts\KronosStep;
use ZuqongTech\Kronos\DAG\WorkflowContext;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Enums\StepStatus;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

class ExecuteWorkflowStep implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $runId, public readonly string $stepName, public int $tries = 1, public int $backoff = 60, public int $timeout = 3600)
    {
    }

    /**
     * Unique key — prevents the same step from being dispatched twice
     * across multiple nodes before it has a chance to start.
     */
    public function uniqueId(): string
    {
        return "kronos:step:{$this->runId}:{$this->stepName}";
    }

    public function handle(KronosOrchestrator $orchestrator): void
    {
        $run = KronosWorkflowRun::with('workflow')->findOrFail($this->runId);

        // Re-check status — guard against race between dispatch and handle
        $stepRun = $run->stepRuns()->where('step_name', $this->stepName)->first();
        if (!$stepRun || $stepRun->status === StepStatus::Completed) {
            return;
        }

        $steps = $run->workflow->definition['steps'] ?? [];
        $stepConfig = collect($steps)->firstWhere('name', $this->stepName);

        if (!$stepConfig || !isset($stepConfig['job'])) {
            $orchestrator->onStepFailed($run, $this->stepName, 'Step job class not configured.');

            return;
        }

        $jobClass = $stepConfig['job'];
        $params = $stepConfig['params'] ?? [];
        $context = new WorkflowContext($run);

        /** @var KronosStep $step */
        $step = app($jobClass, $params);
        $output = [];

        try {
            $result = $step->handle($context);
            // Fix #17: flush dirty context writes in one DB round-trip after handle()
            $context->flush();
            if (is_array($result)) {
                $output = $result;
            }
        } catch (Throwable $e) {
            $context->flush(); // persist partial context even on failure
            $willRetry = $this->attempts() < $this->tries;
            $orchestrator->onStepFailed($run, $this->stepName, $e->getMessage(), $willRetry);

            if ($willRetry) {
                throw $e; // let Laravel retry
            }

            return;
        }

        $orchestrator->onStepCompleted($run, $this->stepName, $output);
    }

    public function failed(Throwable $exception): void
    {
        $run = KronosWorkflowRun::find($this->runId);
        if ($run) {
            app(KronosOrchestrator::class)
                ->onStepFailed($run, $this->stepName, $exception->getMessage(), false);
        }
    }
}
