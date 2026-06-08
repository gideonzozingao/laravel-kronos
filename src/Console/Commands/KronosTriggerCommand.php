<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;

class KronosTriggerCommand extends Command
{
    protected $signature = 'kronos:trigger {workflow : Workflow name} {--context= : JSON context to inject}';
    protected $description = 'Manually trigger a Kronos workflow';

    public function handle(KronosOrchestrator $orchestrator): int
    {
        $name = $this->argument('workflow');
        $context = [];

        if ($raw = $this->option('context')) {
            $context = json_decode($raw, true) ?? [];
        }

        $this->info("Triggering workflow: <comment>{$name}</comment>");

        $runId = $orchestrator->trigger($name, $context);

        $this->info("✔ Workflow run dispatched. Run ID: <comment>{$runId}</comment>");

        return self::SUCCESS;
    }
}