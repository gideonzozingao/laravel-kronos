<?php

declare(strict_types=1);

namespace ZuqongTech\Kronos\Console\Commands;

use Illuminate\Console\Command;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

class KronosRunWorkflowCommand extends Command
{
    protected $signature = 'kronos:rebuild';

    protected $description = 'Force a full rebuild of the Kronos config file and Redis store';

    public function handle(KronosConfigWriter $kronosConfigWriter): int
    {
        $this->info('Rebuilding Kronos configuration...');
        $kronosConfigWriter->rebuildFromDatabase();
        $this->info('✔ kronos.yaml and Redis store updated.');

        return self::SUCCESS;
    }
}
