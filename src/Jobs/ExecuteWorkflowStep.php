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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId, public readonly string $stepName, public int $tries = 1, public int $backoff = 60, public int $timeout = 3600) {}

    /**
     * Unique key — prevents the same step from being dispatched twice
     * across multiple nodes before it has a chance to start.
     */
    public function uniqueId(): string
    {
        return sprintf('kronos:step:%d:%s', $this->runId, $this->stepName);
    }

    public function handle(KronosOrchestrator $kronosOrchestrator): void
    {
        $kronosWorkflowRun = KronosWorkflowRun::with('workflow')->findOrFail($this->runId);

        // Re-check status — guard against race between dispatch and handle
        $stepRun = $kronosWorkflowRun->stepRuns()->where('step_name', $this->stepName)->first();
        if (!$stepRun || $stepRun->status === StepStatus::Completed) {
            return;
        }

        $steps = $kronosWorkflowRun->workflow->definition['steps'] ?? [];
        $stepConfig = collect($steps)->firstWhere('name', $this->stepName);

        if (!$stepConfig || !isset($stepConfig['job'])) {
            $kronosOrchestrator->onStepFailed($kronosWorkflowRun, $this->stepName, 'Step job class not configured.');

            return;
        }

        $jobClass = $stepConfig['job'];
        $params = $stepConfig['params'] ?? [];
        $workflowContext = new WorkflowContext($kronosWorkflowRun);

        /** @var KronosStep $step */
        $step = app($jobClass, $params);
        $output = [];

        try {
            $result = $step->handle($workflowContext);
            // Fix #17: flush dirty context writes in one DB round-trip after handle()
            $workflowContext->flush();
            if (is_array($result)) {
                $output = $result;
            }
        } catch (Throwable $throwable) {
            $workflowContext->flush(); // persist partial context even on failure
            $willRetry = $this->attempts() < $this->tries;
            $kronosOrchestrator->onStepFailed($kronosWorkflowRun, $this->stepName, $throwable->getMessage(), $willRetry);

            if ($willRetry) {
                throw $throwable; // let Laravel retry
            }

            return;
        }

        $kronosOrchestrator->onStepCompleted($kronosWorkflowRun, $this->stepName, $output);
    }

    public function failed(Throwable $throwable): void
    {
        $run = KronosWorkflowRun::find($this->runId);
        if ($run) {
            app(KronosOrchestrator::class)
                ->onStepFailed($run, $this->stepName, $throwable->getMessage(), false);
        }
    }
}
