<?php

use ZuqongTech\Kronos\DAG\WorkflowContext;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

describe('WorkflowContext', function (): void {

    it('reads and writes values', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => []]);
        $ctx = new WorkflowContext($run);

        $ctx->set('count', 42);

        expect($ctx->get('count'))->toBe(42)
            ->and($ctx->has('count'))->toBeTrue()
            ->and($ctx->get('missing', 'default'))->toBe('default');
    });

    it('does not persist to DB until flush() is called', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => []]);
        $ctx = new WorkflowContext($run);

        $ctx->set('key', 'value');

        // DB not yet updated
        expect($run->fresh()->context)->not->toHaveKey('key');

        $ctx->flush();

        // Now it is
        expect($run->fresh()->context)->toHaveKey('key')
            ->and($run->fresh()->context['key'])->toBe('value');
    });

    it('flush() is a no-op when nothing is dirty', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => ['existing' => true]]);
        $ctx = new WorkflowContext($run);

        // No set() calls — flush should not touch the DB
        // (We verify by checking no queries fire — approximated by ensuring
        //  the context is unchanged.)
        $ctx->flush();

        expect($run->fresh()->context['existing'])->toBeTrue();
    });

    it('merges multiple keys in a single flush', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => []]);
        $ctx = new WorkflowContext($run);

        $ctx->merge(['a' => 1, 'b' => 2, 'c' => 3]);
        $ctx->flush();

        $context = $run->fresh()->context;
        expect($context['a'])->toBe(1)
            ->and($context['b'])->toBe(2)
            ->and($context['c'])->toBe(3);
    });

    it('forgets a key and persists on flush', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => ['keep' => true, 'remove' => true]]);
        $ctx = new WorkflowContext($run);

        $ctx->forget('remove');
        $ctx->flush();

        $context = $run->fresh()->context;
        expect($context)->toHaveKey('keep')
            ->and($context)->not->toHaveKey('remove');
    });

    it('checkpoint() persists immediately regardless of dirty flag', function (): void {
        $run = KronosWorkflowRun::factory()->create(['context' => []]);
        $ctx = new WorkflowContext($run);

        $ctx->set('mid_step', true);
        $ctx->checkpoint(); // force immediate persist

        expect($run->fresh()->context)->toHaveKey('mid_step');
    });
});
