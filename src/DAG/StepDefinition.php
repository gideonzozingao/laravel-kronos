<?php

namespace ZuqongTech\Kronos\DAG;

use Closure;
use InvalidArgumentException;
use ZuqongTech\Kronos\Contracts\KronosStep;

class StepDefinition
{
    protected string $jobClass;

    protected array $dependsOn = [];

    protected int $retries = 1;

    protected int $retryDelay = 60;

    protected int $timeout = 3600;

    protected bool $isParallel = false;

    protected ?Closure $onFailure = null;

    protected ?Closure $onSuccess = null;

    protected ?string $condition = null;

    protected array $params = [];

    public function __construct(
        protected string $name,
        protected WorkflowDefinition $workflow,
    ) {}

    /**
     * Set the job class to execute for this step.
     *
     * Fix #19: validates class exists and implements KronosStep at definition time.
     */
    public function run(string $jobClass, array $params = []): static
    {
        if (!class_exists($jobClass)) {
            throw new InvalidArgumentException(
                "Step [{$this->name}]: job class [{$jobClass}] does not exist.",
            );
        }

        if (!is_a($jobClass, KronosStep::class, true)) {
            throw new InvalidArgumentException(
                "Step [{$this->name}]: [{$jobClass}] must implement ".KronosStep::class.'.',
            );
        }

        $this->jobClass = $jobClass;
        $this->params = $params;

        return $this;
    }

    /** Declare upstream step dependencies. */
    public function after(string ...$stepNames): static
    {
        $this->dependsOn = array_merge($this->dependsOn, $stepNames);

        return $this;
    }

    /** Set number of retry attempts on failure. */
    public function retries(int $count, int $delaySeconds = 60): static
    {
        $this->retries = max(1, $count);
        $this->retryDelay = $delaySeconds;

        return $this;
    }

    /** Set step execution timeout in seconds. */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /** Mark this step as part of a parallel group. */
    public function parallel(bool $flag = true): static
    {
        $this->isParallel = $flag;

        return $this;
    }

    /** Skip this step if a context key evaluates to falsy. */
    public function skipUnless(string $contextKey): static
    {
        $this->condition = $contextKey;

        return $this;
    }

    /** Step-level failure callback. */
    public function onFailure(Closure $callback): static
    {
        $this->onFailure = $callback;

        return $this;
    }

    /** Step-level success callback. */
    public function onSuccess(Closure $callback): static
    {
        $this->onSuccess = $callback;

        return $this;
    }

    /** Return to parent workflow for chaining. */
    public function workflow(): WorkflowDefinition
    {
        return $this->workflow;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getJobClass(): string
    {
        return $this->jobClass;
    }

    public function getDependsOn(): array
    {
        return $this->dependsOn;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function isParallel(): bool
    {
        return $this->isParallel;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'job' => $this->jobClass ?? null,
            'params' => $this->params,
            'depends_on' => $this->dependsOn,
            'retries' => $this->retries,
            'retry_delay' => $this->retryDelay,
            'timeout' => $this->timeout,
            'parallel' => $this->isParallel,
            'condition' => $this->condition,
        ];
    }
}
