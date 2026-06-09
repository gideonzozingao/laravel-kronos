<?php

namespace ZuqongTech\Kronos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ZuqongTech\Kronos\Enums\StepStatus;
use ZuqongTech\Kronos\Models\KronosStepRun;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

/**
 * Fix #12/#21: Eloquent factory for KronosStepRun.
 *
 * @extends Factory<KronosStepRun>
 */
class KronosStepRunFactory extends Factory
{
    protected $model = KronosStepRun::class;

    public function definition(): array
    {
        return [
            'workflow_run_id' => KronosWorkflowRun::factory(),
            'step_name' => $this->faker->slug(2),
            'status' => StepStatus::Pending,
            'output' => null,
            'attempt' => 1,
            'exception' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function completed(array $output = []): static
    {
        return $this->state([
            'status' => StepStatus::Completed,
            'output' => $output,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $exception = 'An error occurred'): static
    {
        return $this->state([
            'status' => StepStatus::Failed,
            'exception' => $exception,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->state([
            'status' => StepStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(['status' => StepStatus::Skipped]);
    }
}
