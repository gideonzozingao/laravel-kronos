<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
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

// Helper: a two-step workflow definition
function twoStepDefinition(): array
{
    return [
        'stop_on_failure' => true,
        'trigger' => ['type' => 'manual'],
        'branches' => [],
        'steps' => [
            ['name' => 'step_one', 'job' => null, 'params' => [], 'depends_on' => [], 'retries' => 1, 'retry_delay' => 30, 'timeout' => 60, 'parallel' => false, 'condition' => null],
            ['name' => 'step_two', 'job' => null, 'params' => [], 'depends_on' => ['step_one'], 'retries' => 1, 'retry_delay' => 30, 'timeout' => 60, 'parallel' => false, 'condition' => null],
        ],
    ];
}

describe('KronosOrchestrator', function (): void {

    it('creates a workflow run and dispatches only the first ready step', function (): void {
        Bus::fake();
        Event::fake();

        $workflow = KronosWorkflow::factory()
            ->withSteps(twoStepDefinition()['steps'])
            ->create(['name' => 'two_step_workflow']);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('two_step_workflow');

        expect($runId)->toBeString()->not->toBeEmpty();

        $run = KronosWorkflowRun::where('run_id', $runId)->first();
        expect($run)->not->toBeNull()
            ->and($run->status)->toBe(RunStatus::Running);

        // step_one has no deps — should be dispatched
        Bus::assertDispatched(
            ExecuteWorkflowStep::class,
            fn ($job) => $job->stepName === 'step_one' && $job->runId === $run->id,
        );

        // step_two depends on step_one — must NOT be dispatched yet
        Bus::assertNotDispatched(
            ExecuteWorkflowStep::class,
            fn ($job) => $job->stepName === 'step_two',
        );
    });

    it('seeds step run records for every step', function (): void {
        Bus::fake();

        $workflow = KronosWorkflow::factory()
            ->withSteps(twoStepDefinition()['steps'])
            ->create(['name' => 'seed_test']);

        $runId = app(KronosOrchestrator::class)->trigger('seed_test');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        expect($run->stepRuns)->toHaveCount(2);

        $names = $run->stepRuns->pluck('step_name')->toArray();
        expect($names)->toContain('step_one')->toContain('step_two');
    });

    it('marks workflow completed when all steps finish', function (): void {
        Event::fake();
        Bus::fake();

        $singleStep = [['name' => 'only_step', 'job' => null, 'params' => [], 'depends_on' => [], 'retries' => 1, 'retry_delay' => 30, 'timeout' => 60, 'parallel' => false, 'condition' => null]];

        $workflow = KronosWorkflow::factory()
            ->withSteps($singleStep)
            ->create(['name' => 'single_step_workflow']);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('single_step_workflow');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        $orchestrator->onStepCompleted($run->fresh(), 'only_step', ['result' => 'ok']);

        expect($run->fresh()->status)->toBe(RunStatus::Completed);
        Event::assertDispatched(WorkflowCompleted::class);
    });

    it('marks workflow failed when stop_on_failure is true and a step fails', function (): void {
        Event::fake();
        Bus::fake();

        $workflow = KronosWorkflow::factory()
            ->withSteps(twoStepDefinition()['steps'])
            ->create(['name' => 'failing_workflow']);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('failing_workflow');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        $orchestrator->onStepFailed($run->fresh(), 'step_one', 'Something exploded', false);

        expect($run->fresh()->status)->toBe(RunStatus::Failed);
        Event::assertDispatched(WorkflowFailed::class);
    });

    it('cancels a running workflow and marks pending steps as skipped', function (): void {
        Bus::fake();

        $workflow = KronosWorkflow::factory()
            ->withSteps(twoStepDefinition()['steps'])
            ->create(['name' => 'cancel_test']);

        $orchestrator = app(KronosOrchestrator::class);
        $runId = $orchestrator->trigger('cancel_test');
        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        $orchestrator->cancel($run->fresh(), 'Test cancellation');

        $refreshed = $run->fresh();
        expect($refreshed->status)->toBe(RunStatus::Cancelled)
            ->and($refreshed->error)->toBe('Test cancellation');

        // step_two was pending — should be skipped
        $skipped = $refreshed->stepRuns()->where('status', StepStatus::Skipped->value)->count();
        expect($skipped)->toBeGreaterThanOrEqual(1);
    });

    it('throws ModelNotFoundException for unknown or disabled workflow', function (): void {
        expect(fn () => app(KronosOrchestrator::class)->trigger('nonexistent'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('isTerminal returns true for completed, failed, and cancelled runs', function (): void {
        $completed = KronosWorkflowRun::factory()->completed()->create();
        $failed = KronosWorkflowRun::factory()->failed()->create();
        $cancelled = KronosWorkflowRun::factory()->cancelled()->create();
        $running = KronosWorkflowRun::factory()->create(); // default = running

        expect($completed->isTerminal())->toBeTrue()
            ->and($failed->isTerminal())->toBeTrue()
            ->and($cancelled->isTerminal())->toBeTrue()
            ->and($running->isTerminal())->toBeFalse();
    });
});
