<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Workflow definitions ───────────────────────────────────────────
        Schema::create('kronos_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('trigger_type')->default('manual');
            $table->string('cron_expression')->nullable();
            $table->string('timezone')->default('UTC');
            $table->json('definition');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'trigger_type']);
        });

        // ── Workflow run instances ─────────────────────────────────────────
        Schema::create('kronos_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('kronos_workflows')->cascadeOnDelete();
            $table->uuid('run_id')->unique();
            $table->string('status')->default('pending');
            $table->json('context')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index('started_at');
        });

        // ── Step run records ───────────────────────────────────────────────
        Schema::create('kronos_step_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('kronos_workflow_runs')->cascadeOnDelete();
            $table->string('step_name');
            $table->string('status')->default('pending');
            $table->json('output')->nullable();
            $table->integer('attempt')->default(1);
            $table->text('exception')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_run_id', 'status']);
            $table->unique(['workflow_run_id', 'step_name']);
        });

        // ── Simple scheduled tasks (non-workflow) ──────────────────────────
        Schema::create('kronos_scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('command');
            $table->string('cron_expression');
            $table->string('timezone')->default('UTC');
            $table->boolean('enabled')->default(true);
            $table->boolean('without_overlapping')->default(false);
            $table->boolean('on_one_server')->default(false);
            $table->boolean('run_in_background')->default(false);
            $table->string('on_failure_webhook')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ── Schedule run history ───────────────────────────────────────────
        Schema::create('kronos_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('kronos_scheduled_tasks')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kronos_schedule_runs');
        Schema::dropIfExists('kronos_scheduled_tasks');
        Schema::dropIfExists('kronos_step_runs');
        Schema::dropIfExists('kronos_workflow_runs');
        Schema::dropIfExists('kronos_workflows');
    }
};
