<?php

namespace ZuqongTech\Kronos\DAG;

use ZuqongTech\Kronos\Models\KronosWorkflowRun;

class WorkflowContext
{
    protected array $data = [];

    public function __construct(protected KronosWorkflowRun $run)
    {
        $this->data = $run->context ?? [];
    }

    /**
     * Get a value from the shared context.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Set a value in the shared context and persist immediately.
     */
    public function set(string $key, mixed $value): static
    {
        data_set($this->data, $key, $value);
        $this->persist();
        return $this;
    }

    /**
     * Merge an array of key-value pairs into context.
     */
    public function merge(array $values): static
    {
        $this->data = array_merge($this->data, $values);
        $this->persist();
        return $this;
    }

    /**
     * Check if a key exists in context.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Forget a key from context.
     */
    public function forget(string $key): static
    {
        unset($this->data[$key]);
        $this->persist();
        return $this;
    }

    /**
     * Get all context data.
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Persist the context back to the workflow run record.
     */
    protected function persist(): void
    {
        $this->run->update(['context' => $this->data]);
    }
}