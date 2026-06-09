<?php

namespace ZuqongTech\Kronos\DAG;

use ZuqongTech\Kronos\Exceptions\KronosDeadlockException;

class DAGResolver
{
    /**
     * Resolve a list of steps into ordered execution batches.
     * Steps within the same batch can run in parallel.
     * Uses Kahn's algorithm for topological sort.
     *
     * @param  array<string, array{depends_on: string[]}>  $steps
     * @return array<int, string[]> Ordered batches of step names
     *
     * @throws KronosDeadlockException
     */
    public function resolve(array $steps): array
    {
        // Build in-degree map and adjacency list
        $inDegree = [];
        $adjacency = [];

        foreach ($steps as $name => $step) {
            $inDegree[$name] ??= 0;
            foreach ($step['depends_on'] ?? [] as $dep) {
                $adjacency[$dep][] = $name;
                $inDegree[$name]++;
            }
        }

        // Collect all zero-degree nodes as the first batch
        $batches = [];
        $queue = array_keys(array_filter($inDegree, fn (int $deg): bool => $deg === 0));
        $processed = 0;

        while ($queue !== []) {
            $batches[] = $queue;
            $nextQueue = [];

            foreach ($queue as $node) {
                $processed++;
                foreach ($adjacency[$node] ?? [] as $dependent) {
                    $inDegree[$dependent]--;
                    if ($inDegree[$dependent] === 0) {
                        $nextQueue[] = $dependent;
                    }
                }
            }

            $queue = $nextQueue;
        }

        // If not all nodes processed, there is a cycle
        if ($processed !== count($steps)) {
            $cycle = array_keys(array_filter($inDegree, fn (int $deg): bool => $deg > 0));
            throw new KronosDeadlockException(
                'Circular dependency detected in workflow DAG. Affected steps: '.implode(', ', $cycle),
            );
        }

        return $batches;
    }

    /**
     * Get all steps that are ready to execute given the set of completed steps.
     *
     * @param  array<string, array{depends_on: string[]}>  $allSteps
     * @param  string[]  $completedSteps
     * @param  string[]  $runningSteps
     * @return string[]
     */
    public function getReadySteps(array $allSteps, array $completedSteps, array $runningSteps = []): array
    {
        $ready = [];

        foreach ($allSteps as $name => $step) {
            // Skip if already completed or running
            if (in_array($name, $completedSteps) || in_array($name, $runningSteps)) {
                continue;
            }

            // Check all dependencies are completed
            $deps = $step['depends_on'] ?? [];
            if (array_diff($deps, $completedSteps) === []) {
                $ready[] = $name;
            }
        }

        return $ready;
    }

    /**
     * Validate a DAG for cycles before persisting.
     *
     * @throws KronosDeadlockException
     */
    public function validate(array $steps): bool
    {
        $this->resolve($steps); // throws if invalid

        return true;
    }
}
