<?php

namespace ZuqongTech\Kronos\DAG;

class TriggerDefinition
{
    protected string $type = 'manual';

    protected ?string $cronExpression = null;

    protected string $timezone = 'UTC';

    protected ?string $modelClass = null;

    protected ?string $modelEvent = null;

    protected ?string $laravelEvent = null;

    protected ?string $afterWorkflow = null;

    protected ?string $webhookPath = null;

    public function __construct(protected WorkflowDefinition $workflow) {}

    /**
     * Trigger on a cron schedule.
     */
    public function cron(string $expression): WorkflowDefinition
    {
        $this->type = 'cron';
        $this->cronExpression = $expression;

        return $this->workflow;
    }

    /**
     * Set timezone for cron trigger.
     */
    public function timezone(string $tz): static
    {
        $this->timezone = $tz;

        return $this;
    }

    /**
     * Trigger when a model event fires.
     */
    public function onModelEvent(string $modelClass, string $event = 'created'): WorkflowDefinition
    {
        $this->type = 'model_event';
        $this->modelClass = $modelClass;
        $this->modelEvent = $event;

        return $this->workflow;
    }

    /**
     * Trigger on a Laravel application event.
     */
    public function onEvent(string $eventClass): WorkflowDefinition
    {
        $this->type = 'laravel_event';
        $this->laravelEvent = $eventClass;

        return $this->workflow;
    }

    /**
     * Trigger after another workflow completes.
     */
    public function afterWorkflow(string $workflowName): WorkflowDefinition
    {
        $this->type = 'workflow_completion';
        $this->afterWorkflow = $workflowName;

        return $this->workflow;
    }

    /**
     * Trigger via an inbound webhook.
     */
    public function webhook(string $path): WorkflowDefinition
    {
        $this->type = 'webhook';
        $this->webhookPath = $path;

        return $this->workflow;
    }

    /**
     * Manual trigger only (default).
     */
    public function manual(): WorkflowDefinition
    {
        $this->type = 'manual';

        return $this->workflow;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCron(): ?string
    {
        return $this->cronExpression;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'cron_expression' => $this->cronExpression,
            'timezone' => $this->timezone,
            'model_class' => $this->modelClass,
            'model_event' => $this->modelEvent,
            'laravel_event' => $this->laravelEvent,
            'after_workflow' => $this->afterWorkflow,
            'webhook_path' => $this->webhookPath,
        ]);
    }
}
