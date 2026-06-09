<?php

use Illuminate\Support\Facades\Route;
use ZuqongTech\Kronos\Http\Controllers\KronosWebhookController;

$prefix = config('kronos.webhook.prefix', 'kronos');

Route::prefix($prefix)->group(function (): void {
    Route::post('/trigger/{workflow}', [KronosWebhookController::class, 'trigger'])
        ->name('kronos.trigger');

    Route::get('/runs/{runId}', [KronosWebhookController::class, 'runStatus'])
        ->name('kronos.run.status');
});
