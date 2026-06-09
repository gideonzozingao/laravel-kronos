<?php

namespace ZuqongTech\Kronos\ReactPHP\Broadcast;

use Illuminate\Support\Facades\Redis;
use Throwable;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;
use ZuqongTech\Kronos\ReactPHP\WebSocket\KronosWebSocketServer;

/**
 * RunBroadcaster — bridges the Orchestrator to the ReactPHP broadcast layer.
 *
 * When the Orchestrator advances a workflow run (step completed, step failed,
 * workflow completed etc.) it calls this broadcaster. If the daemon is running,
 * the WebSocket server receives the push directly in-process. If only the
 * Laravel queue worker is running (non-daemon mode), the event is published
 * to the Redis `kronos:run:broadcast` channel so the daemon can forward it.
 *
 * This means the Orchestrator doesn't need to know whether it's running
 * inside the daemon or a standard Laravel queue worker — it always calls
 * RunBroadcaster and the broadcaster routes appropriately.
 */
class RunBroadcaster
{
    protected const CHANNEL = 'kronos:run:broadcast';

    public function __construct(
        protected ?KronosWebSocketServer $wsServer = null,
    ) {}

    /**
     * Broadcast a step status change.
     */
    public function stepUpdated(
        KronosWorkflowRun $kronosWorkflowRun,
        string $stepName,
        string $status,
        array $extra = [],
    ): void {
        $this->publish($kronosWorkflowRun->run_id, 'step.updated', array_merge([
            'step' => $stepName,
            'status' => $status,
        ], $extra));
    }

    /**
     * Broadcast a workflow-level status change.
     */
    public function workflowUpdated(KronosWorkflowRun $kronosWorkflowRun, string $status, array $extra = []): void
    {
        $this->publish($kronosWorkflowRun->run_id, 'workflow.updated', array_merge([
            'workflow' => $kronosWorkflowRun->workflow->name,
            'status' => $status,
        ], $extra));
    }

    /**
     * Core publish — routes to in-process WebSocket server or Redis pub/sub.
     */
    protected function publish(string $runId, string $event, array $data): void
    {
        $payload = json_encode(array_merge($data, [
            'run_id' => $runId,
            'event' => $event,
            'ts' => now()->toIso8601String(),
        ]));

        // In-process path: daemon is running, push directly
        if ($this->wsServer instanceof KronosWebSocketServer) {
            $this->wsServer->broadcast($payload);
        }

        // Always also publish to Redis so other nodes / listeners receive it
        try {
            Redis::connection(config('kronos.redis_connection', 'default'))
                ->publish(self::CHANNEL, $payload);
        } catch (Throwable) {
            // Redis unavailable — in-process push above is still delivered
        }
    }
}
