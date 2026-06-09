<?php

namespace ZuqongTech\Kronos\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ZuqongTech\Kronos\Models\KronosWorkflow;

/**
 * Fix #12/#21: Eloquent factory for KronosWorkflow.
 *
 * @extends Factory<KronosWorkflow>
 */
class KronosWorkflowFactory extends Factory
{
    protected $model = KronosWorkflow::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(3),
            'trigger_type' => 'manual',
            'cron_expression' => null,
            'timezone' => 'UTC',
            'enabled' => true,
            'definition' => [
                'stop_on_failure' => true,
                'trigger' => ['type' => 'manual'],
                'steps' => [],
                'branches' => [],
            ],
        ];
    }

    /** Create a cron-triggered workflow. */
    public function cron(string $expression = '0 9 * * *', string $timezone = 'UTC'): static
    {
        return $this->state([
            'trigger_type' => 'cron',
            'cron_expression' => $expression,
            'timezone' => $timezone,
            'definition' => array_merge($this->definition()['definition'], [
                'trigger' => [
                    'type' => 'cron',
                    'cron_expression' => $expression,
                    'timezone' => $timezone,
                ],
            ]),
        ]);
    }

    /** Create a disabled workflow. */
    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    /** Create a workflow with a given set of step configs. */
    public function withSteps(array $steps): static
    {
        return $this->state(fn ($attrs) => [
            'definition' => array_merge($attrs['definition'], ['steps' => $steps]),
        ]);
    }
}
