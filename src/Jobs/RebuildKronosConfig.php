<?php

namespace ZuqongTech\Kronos\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ZuqongTech\Kronos\Writers\KronosConfigWriter;

class RebuildKronosConfig implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Unique key — all rebuild requests collapse into a single job execution.
     * This is the debounce mechanism: 50 rapid DB changes = 1 rebuild.
     */
    public function uniqueId(): string
    {
        return 'kronos:config:rebuild';
    }

    /**
     * How long (seconds) the unique lock is held before a new dispatch is allowed.
     * Prevents a flood of rebuilds while one is still running.
     */
    public int $uniqueFor = 10;

    public function handle(KronosConfigWriter $writer): void
    {
        $writer->rebuildFromDatabase();
    }
}