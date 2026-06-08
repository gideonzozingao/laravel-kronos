<?php

namespace ZuqongTech\Kronos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Exceptions\KronosWorkflowNotFoundException;

class KronosWebhookController extends Controller
{
    public function __construct(protected KronosOrchestrator $orchestrator) {}

    /**
     * Trigger a workflow via an authenticated webhook.
     *
     * POST /kronos/trigger/{workflow}
     */
    public function trigger(Request $request, string $workflow): JsonResponse
    {
        // Validate webhook secret
        $secret = config('kronos.webhook.secret');
        if ($secret && $request->header('X-Kronos-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $context = $request->input('context', []);

        try {
            $runId = $this->orchestrator->trigger($workflow, $context);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => "Workflow [{$workflow}] not found or disabled."], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Workflow triggered.',
            'run_id'  => $runId,
        ]);
    }

    /**
     * Get the status of a workflow run.
     *
     * GET /kronos/runs/{runId}
     */
    public function runStatus(Request $request, string $runId): JsonResponse
    {
        $secret = config('kronos.webhook.secret');
        if ($secret && $request->header('X-Kronos-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $run = \ZuqongTech\Kronos\Models\KronosWorkflowRun::with(['workflow', 'stepRuns'])
            ->where('run_id', $runId)
            ->first();

        if (!$run) {
            return response()->json(['error' => 'Run not found.'], 404);
        }

        return response()->json([
            'run_id'      => $run->run_id,
            'workflow'    => $run->workflow->name,
            'status'      => $run->status->value,
            'started_at'  => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration'    => $run->duration,
            'context'     => $run->context,
            'steps'       => $run->stepRuns->map(fn ($s) => [
                'name'        => $s->step_name,
                'status'      => $s->status->value,
                'attempt'     => $s->attempt,
                'duration'    => $s->duration,
                'output'      => $s->output,
                'exception'   => $s->exception,
            ]),
        ]);
    }
}