<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\Contracts;

use ZuqongTech\Kronos\DAG\WorkflowContext;

interface KronosStep
{
    /**
     * Execute the step logic.
     *
     * Receive the shared workflow context for reading upstream data
     * and writing output for downstream steps.
     *
     * Return an array to store as step output, or void.
     */
    public function handle(WorkflowContext $workflowContext): ?array;
}
