<?php

namespace ZuqongTech\Kronos\DAG;

use Closure;

class BranchDefinition
{
    protected array $arms = [];

    protected ?array $otherwiseSteps = null;

    public function __construct(protected WorkflowDefinition $workflow) {}

    /**
     * Define a conditional arm. The condition receives the WorkflowContext.
     */
    public function when(Closure $condition): BranchArm
    {
        $branchArm = new BranchArm($condition, $this);
        $this->arms[] = $branchArm;

        return $branchArm;
    }

    /**
     * Define the fallback arm when no condition matches.
     */
    public function otherwise(): BranchArm
    {
        $branchArm = new BranchArm(fn (): true => true, $this, isDefault: true);
        $this->otherwiseSteps = [];
        $this->arms[] = $branchArm;

        return $branchArm;
    }

    /**
     * Close the branch and return to workflow for further chaining.
     */
    public function endBranch(): WorkflowDefinition
    {
        return $this->workflow;
    }

    public function getArms(): array
    {
        return $this->arms;
    }

    public function toArray(): array
    {
        return [
            'arms' => array_map(fn (BranchArm $branchArm) => $branchArm->toArray(), $this->arms),
        ];
    }
}
