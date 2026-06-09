<?php

namespace ZuqongTech\Kronos\DAG;

use ZuqongTech\Kronos\Models\KronosWorkflowRun;

class WorkflowContext
{
    protected array $data = [];

    // Fix #17: dirty flag — batch all set() calls into a single flush()
    protected bool $dirty = false;

    public function __construct(protected KronosWorkflowRun $run)
    {
        $this->data = $run->context ?? [];
    }

    /** Read a value from the shared context. */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /** Write a value — queued for flush, not immediately persisted. */
    public function set(string $key, mixed $value): static
    {
        data_set($this->data, $key, $value);
        $this->dirty = true;

        return $this;
    }

    /** Bulk-write — queued for flush. */
    public function merge(array $values): static
    {
        $this->data = array_merge($this->data, $values);
        $this->dirty = true;

        return $this;
    }

    /** Check existence. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** Remove a key — queued for flush. */
    public function forget(string $key): static
    {
        unset($this->data[$key]);
        $this->dirty = true;

        return $this;
    }

    /** Get all context data. */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Persist dirty data to DB in a single UPDATE.
     * Called by ExecuteWorkflowStep after handle() completes or fails —
     * not on every individual set() call.
     */
    public function flush(): void
    {
        if ($this->dirty) {
            $this->run->update(['context' => $this->data]);
            $this->dirty = false;
        }
    }

    /**
     * Force an immediate persist regardless of dirty flag.
     * Useful for long-running steps that want mid-step checkpointing.
     */
    public function checkpoint(): void
    {
        $this->run->update(['context' => $this->data]);
        $this->dirty = false;
    }
}
