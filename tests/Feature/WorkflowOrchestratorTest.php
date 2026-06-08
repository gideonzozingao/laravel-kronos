<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Enums\RunStatus;
use ZuqongTech\Kronos\Enums\StepStatus;
use ZuqongTech\Kronos\Events\WorkflowCompleted;
use ZuqongTech\Kronos\Events\WorkflowFailed;
use ZuqongTech\Kronos\Jobs\ExecuteWorkflowStep;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

describe('KronosOrchestrator', function () {

    it('creates a workflow run and dispatches ready steps', function () {
        Bus::fake();
        Event::fake();

        $workflow = KronosWorkflow::factory()->create([
            'name'    => 'test_workflow',
            'enabled' => true,
            'definition' => [
                'stop_on_failure' => true,
                'steps' => [
                    ['name' => 'step_one', 'job' => 'App\\Jobs\\StepOne', 'depends_on' => [], 'retries' => 0, 'timeout' => 60, 'retry_delay' => 30],
                    ['name' => 'step_two', 'job' => 'App\\Jobs\\StepTwo', 'depends_on' => ['step_one'], 'retries' => 0, 'timeout' => 60, 'retry_delay' => 30],
                ],
                'trigger' => ['type' => 'manual'],
            ],
        ]);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('test_workflow');

        expect($runId)->toBeString();

        $run = KronosWorkflowRun::where('run_id', $runId)->first();
        expect($run)->not->toBeNull()
            ->and($run->status->value)->toBe(RunStatus::Running->value);

        Bus::assertDispatched(ExecuteWorkflowStep::class, fn ($job) =>
            $job->stepName === 'step_one' && $job->runId === $run->id
        );

        // step_two should NOT be dispatched yet (depends on step_one)
        Bus::assertNotDispatched(ExecuteWorkflowStep::class, fn ($job) =>
            $job->stepName === 'step_two'
        );
    });

    it('marks workflow completed when all steps finish', function () {
        Event::fake();
        Bus::fake();

        $workflow = KronosWorkflow::factory()->create([
            'name'    => 'simple_workflow',
            'enabled' => true,
            'definition' => [
                'stop_on_failure' => true,
                'steps' => [
                    ['name' => 'only_step', 'job' => 'App\\Jobs\\OnlyStep', 'depends_on' => [], 'retries' => 0, 'timeout' => 60, 'retry_delay' => 30],
                ],
                'trigger' => ['type' => 'manual'],
            ],
        ]);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('simple_workflow');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        // Simulate step completion
        $orchestrator->onStepCompleted($run, 'only_step', ['result' => 'ok']);

        $run->refresh();
        expect($run->status->value)->toBe(RunStatus::Completed->value);

        Event::assertDispatched(WorkflowCompleted::class);
    });

    it('marks workflow failed and stops on step failure when stop_on_failure is true', function () {
        Event::fake();
        Bus::fake();

        $workflow = KronosWorkflow::factory()->create([
            'name'    => 'failing_workflow',
            'enabled' => true,
            'definition' => [
                'stop_on_failure' => true,
                'steps' => [
                    ['name' => 'bad_step', 'job' => 'App\\Jobs\\BadStep', 'depends_on' => [], 'retries' => 0, 'timeout' => 60, 'retry_delay' => 30],
                    ['name' => 'good_step', 'job' => 'App\\Jobs\\GoodStep', 'depends_on' => ['bad_step'], 'retries' => 0, 'timeout' => 60, 'retry_delay' => 30],
                ],
                'trigger' => ['type' => 'manual'],
            ],
        ]);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('failing_workflow');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        $orchestrator->onStepFailed($run, 'bad_step', 'Something exploded', false);

        $run->refresh();
        expect($run->status->value)->toBe(RunStatus::Failed->value);

        Event::assertDispatched(WorkflowFailed::class);
    });

    it('throws ModelNotFoundException for unknown workflow', function () {
        $orchestrator = app(KronosOrchestrator::class);

        expect(fn () => $orchestrator->trigger('nonexistent_workflow'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });
});
