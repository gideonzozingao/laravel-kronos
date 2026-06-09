<?php

use ZuqongTech\Kronos\DAG\DAGResolver;
use ZuqongTech\Kronos\Exceptions\KronosDeadlockException;

describe('DAGResolver', function (): void {

    it('resolves a linear chain into sequential batches', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'validate' => ['depends_on' => []],
            'calculate' => ['depends_on' => ['validate']],
            'notify' => ['depends_on' => ['calculate']],
        ];

        $batches = $resolver->resolve($steps);

        expect($batches)->toHaveCount(3)
            ->and($batches[0])->toBe(['validate'])
            ->and($batches[1])->toBe(['calculate'])
            ->and($batches[2])->toBe(['notify']);
    });

    it('groups parallel steps into a single batch', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'validate' => ['depends_on' => []],
            'statements' => ['depends_on' => ['validate']],
            'reports' => ['depends_on' => ['validate']],
            'notify' => ['depends_on' => ['statements', 'reports']],
        ];

        $batches = $resolver->resolve($steps);

        expect($batches)->toHaveCount(3)
            ->and($batches[0])->toBe(['validate'])
            ->and($batches[1])->toContain('statements')->toContain('reports')
            ->and($batches[2])->toBe(['notify']);
    });

    it('resolves steps with no dependencies as the first batch together', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'step_a' => ['depends_on' => []],
            'step_b' => ['depends_on' => []],
            'step_c' => ['depends_on' => ['step_a', 'step_b']],
        ];

        $batches = $resolver->resolve($steps);

        expect($batches[0])->toContain('step_a')->toContain('step_b')
            ->and($batches[1])->toBe(['step_c']);
    });

    it('throws KronosDeadlockException on circular dependency', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'step_a' => ['depends_on' => ['step_c']],
            'step_b' => ['depends_on' => ['step_a']],
            'step_c' => ['depends_on' => ['step_b']],
        ];

        expect(fn (): array => $resolver->resolve($steps))
            ->toThrow(KronosDeadlockException::class);
    });

    it('returns ready steps given completed set', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'validate' => ['depends_on' => []],
            'calculate' => ['depends_on' => ['validate']],
            'statements' => ['depends_on' => ['validate']],
            'notify' => ['depends_on' => ['calculate', 'statements']],
        ];

        $ready = $resolver->getReadySteps($steps, ['validate'], []);

        expect($ready)->toContain('calculate')->toContain('statements')
            ->and($ready)->not->toContain('notify');
    });

    it('does not return running steps as ready', function (): void {
        $resolver = new DAGResolver;

        $steps = [
            'validate' => ['depends_on' => []],
            'calculate' => ['depends_on' => ['validate']],
        ];

        $ready = $resolver->getReadySteps($steps, ['validate'], ['calculate']);

        expect($ready)->toBeEmpty();
    });

    it('handles a single step with no dependencies', function (): void {
        $resolver = new DAGResolver;

        $steps = ['only_step' => ['depends_on' => []]];
        $batches = $resolver->resolve($steps);

        expect($batches)->toHaveCount(1)
            ->and($batches[0])->toBe(['only_step']);
    });

    it('handles empty steps', function (): void {
        $resolver = new DAGResolver;
        $batches = $resolver->resolve([]);
        expect($batches)->toBeEmpty();
    });
});
