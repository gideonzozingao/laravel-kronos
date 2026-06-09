<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;
use ZuqongTech\Kronos\Models\KronosWorkflowRun;

/**
 * Fix #23: cancel a running or pending workflow run.
 */
class KronosCancelCommand extends Command
{
    protected $signature = 'kronos:cancel
                            {run_id : The UUID of the workflow run to cancel}
                            {--reason= : Optional cancellation reason}';

    protected $description = 'Cancel a running or pending Kronos workflow run';

    public function handle(KronosOrchestrator $kronosOrchestrator): int
    {
        $runId = $this->argument('run_id');
        $reason = $this->option('reason') ?? 'Cancelled via kronos:cancel';

        $run = KronosWorkflowRun::where('run_id', $runId)->first();

        if (!$run) {
            $this->error(sprintf('No workflow run found with ID [%s].', $runId));

            return self::FAILURE;
        }

        if ($run->isTerminal()) {
            $this->warn(sprintf('Run [%s] is already in a terminal state (%s). Nothing to cancel.', $runId, $run->status->value));

            return self::SUCCESS;
        }

        $kronosOrchestrator->cancel($run, $reason);

        $this->info(sprintf('✔ Run [%s] cancelled.', $runId));

        return self::SUCCESS;
    }
}
