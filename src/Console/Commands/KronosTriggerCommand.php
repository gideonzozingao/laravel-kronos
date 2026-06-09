<?php

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Engine\KronosOrchestrator;

class KronosTriggerCommand extends Command
{
    protected $signature = 'kronos:trigger {workflow : Workflow name} {--context= : JSON context to inject}';

    protected $description = 'Manually trigger a Kronos workflow';

    public function handle(KronosOrchestrator $kronosOrchestrator): int
    {
        $name = $this->argument('workflow');
        $context = [];

        if ($raw = $this->option('context')) {
            $context = json_decode($raw, true) ?? [];
        }

        $this->info(sprintf('Triggering workflow: <comment>%s</comment>', $name));

        $runId = $kronosOrchestrator->trigger($name, $context);

        $this->info(sprintf('✔ Workflow run dispatched. Run ID: <comment>%s</comment>', $runId));

        return self::SUCCESS;
    }
}
