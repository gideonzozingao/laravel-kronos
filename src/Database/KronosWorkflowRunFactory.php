<?php

namespace ZuqongTech\Kronos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ZuqongTech\Kronos\Enums\RunStatus;
use ZuqongTech\Kronos\Models\KronosWorkflow;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

/**
 * Fix #12/#21: Eloquent factory for KronosWorkflowRun.
 *
 * @extends Factory<KronosWorkflowRun>
 */
class KronosWorkflowRunFactory extends Factory
{
    protected $model = KronosWorkflowRun::class;

    public function definition(): array
    {
        return [
            'workflow_id' => KronosWorkflow::factory(),
            'run_id' => Str::uuid()->toString(),
            'status' => RunStatus::Running,
            'context' => [],
            'error' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => RunStatus::Completed,
            'finished_at' => now()->addMinutes(2),
        ]);
    }

    public function failed(string $reason = 'Step failed'): static
    {
        return $this->state([
            'status' => RunStatus::Failed,
            'error' => $reason,
            'finished_at' => now()->addMinutes(1),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => RunStatus::Cancelled,
            'finished_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'status' => RunStatus::Pending,
            'started_at' => null,
        ]);
    }
}
