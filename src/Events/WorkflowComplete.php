<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\Events;

use ZuqongTech\Kronos\Models\KronosWorkflowRun;

class WorkflowCompleted
{
    public function __construct(public readonly KronosWorkflowRun $run) {}
}
