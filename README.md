# ⏱ Laravel Kronos

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zuqongtech/laravel-kronos.svg?style=flat-square)](https://packagist.org/packages/zuqongtech/laravel-kronos)
[![Total Downloads](https://img.shields.io/packagist/dt/zuqongtech/laravel-kronos.svg?style=flat-square)](https://packagist.org/packages/zuqongtech/laravel-kronos)
[![Tests](https://img.shields.io/github/actions/workflow/status/zuqongtech/laravel-kronos/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/zuqongtech/laravel-kronos/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/zuqongtech/laravel-kronos/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/zuqongtech/laravel-kronos/actions)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue?style=flat-square)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-11%2B%20%7C%2012%2B-red?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/zuqongtech/laravel-kronos.svg?style=flat-square)](LICENSE)

> **A reactive workflow orchestration and scheduling engine for Laravel.**
>
> Rule-driven. DAG-based. Multi-node safe.

Kronos bridges the gap between Laravel's built-in cron scheduler and a full workflow orchestration platform. It watches your Eloquent models, evaluates configurable rules, and reactively writes a canonical `kronos.yaml` / Redis configuration — then executes complex multi-step DAG workflows with branching, parallel execution, retries, shared context, and a full audit trail.

Think of it as **Laravel's scheduler meets Apache Airflow**, natively integrated with Eloquent, Horizon, and Filament.

---

## Table of Contents

- [Why Kronos?](#why-kronos)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
  - [Rule Engine](#rule-engine)
  - [Config Writer (YAML + Redis)](#config-writer-yaml--redis)
  - [Workflow Orchestration](#workflow-orchestration)
  - [DAG Resolution](#dag-resolution)
  - [Workflow Context](#workflow-context)
  - [Triggers](#triggers)
  - [Branching](#branching)
- [Building Steps](#building-steps)
- [Scheduled Tasks (Simple Cron)](#scheduled-tasks-simple-cron)
- [Multi-Node Deployments](#multi-node-deployments)
- [Filament UI](#filament-ui)
- [Webhook API](#webhook-api)
- [Artisan Commands](#artisan-commands)
- [Events](#events)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [FAQ](#faq)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

---

## Why Kronos?

Laravel's built-in scheduler is great for simple cron tasks but falls short when you need:

| Need | Laravel Scheduler | Kronos |
|---|---|---|
| Database-driven schedules | ❌ Hardcoded in code | ✅ DB + YAML + Redis |
| Multi-step workflow DAGs | ❌ | ✅ |
| Reactive DB-change triggers | ❌ | ✅ Rule Engine |
| Step dependency resolution | ❌ | ✅ Kahn's Algorithm |
| Parallel step execution | ❌ | ✅ |
| Conditional branching | ❌ | ✅ |
| Shared inter-step context | ❌ | ✅ |
| Per-run audit trail | ❌ | ✅ |
| Multi-node safe execution | Partial (`onOneServer`) | ✅ Full distributed locking |
| Admin UI | ❌ | ✅ Filament v3 plugin |
| Version-controlled config | ❌ | ✅ `kronos.yaml` |

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^11.0` or `^12.0` |
| Redis | Any (for locking + multi-node) |
| Filament *(optional)* | `^3.0` |

---

## Installation

Install via Composer:

```bash
composer require zuqongtech/laravel-kronos
```

Run the install command to publish config and run migrations:

```bash
php artisan kronos:install
```

This publishes `config/kronos.php`, runs the five Kronos migrations, and creates an empty `storage/kronos.yaml`.

**Manual publish (optional):**

```bash
php artisan vendor:publish --tag=kronos-config
php artisan vendor:publish --tag=kronos-migrations
php artisan migrate
```

---

## Quick Start

### 1. Register rules and workflows in a Service Provider

Create a dedicated provider or use your `AppServiceProvider`:

```php
<?php

namespace App\Providers;

use App\Jobs\GenerateMemberStatementsJob;
use App\Jobs\NotifyStakeholdersJob;
use App\Jobs\ValidateContributionsJob;
use App\Models\ScheduledTask;
use Illuminate\Support\ServiceProvider;
use ZuqongTech\Kronos\Facades\Kronos;

class KronosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ── Rule: when a ScheduledTask is enabled, write it to kronos.yaml ──
        Kronos::rule('activate_scheduled_task')
            ->when(ScheduledTask::class, fn ($task) => $task->is_enabled)
            ->onEvents(['created', 'updated'])
            ->produces(fn ($task) => [
                'id'                  => $task->id,
                'command'             => $task->command,
                'cron_expression'     => $task->cron_expression,
                'timezone'            => $task->timezone ?? 'UTC',
                'without_overlapping' => true,
                'on_one_server'       => true,
                'enabled'             => true,
            ]);

        // ── Workflow: multi-step monthly payroll ───────────────────────────
        Kronos::workflow('monthly_payroll')
            ->trigger()->cron('0 0 1 * *')->timezone('Pacific/Port_Moresby')

            ->step('validate_contributions')
                ->run(ValidateContributionsJob::class)
                ->retries(3, delaySeconds: 120)
                ->timeout(300)

            ->step('generate_statements')
                ->run(GenerateMemberStatementsJob::class)
                ->after('validate_contributions')
                ->timeout(600)

            ->step('notify_stakeholders')
                ->run(NotifyStakeholdersJob::class)
                ->after('generate_statements')
                ->retries(2)

            ->onFailure(fn () => \Log::critical('Monthly payroll workflow failed'))
            ->register();
    }
}
```

### 2. Implement your step jobs

```php
<?php

namespace App\Jobs;

use ZuqongTech\Kronos\Contracts\KronosStep;
use ZuqongTech\Kronos\DAG\WorkflowContext;

class ValidateContributionsJob implements KronosStep
{
    public function handle(WorkflowContext $context): array
    {
        $result = ContributionValidator::run();

        // Write to shared context — available to all downstream steps
        $context->set('validated_count', $result->count);
        $context->set('has_errors', $result->hasErrors());

        return ['count' => $result->count];
    }
}
```

### 3. Trigger a workflow manually

```bash
php artisan kronos:trigger monthly_payroll
```

Or via the facade:

```php
$runId = Kronos::trigger('monthly_payroll', ['initiated_by' => 'admin']);
```

---

## Core Concepts

### Rule Engine

The rule engine is the reactive heart of Kronos. Rules watch Eloquent model events and, when a condition is met, dispatch a debounced rebuild of the canonical config.

```php
Kronos::rule('rule_name')
    ->when(MyModel::class, fn ($model) => $model->is_active)
    ->onEvents(['created', 'updated'])   // defaults to all three
    ->produces(fn ($model) => [
        'command'         => "my-command:{$model->id}",
        'cron_expression' => $model->cron,
        'timezone'        => $model->timezone,
        'enabled'         => true,
    ]);
```

**Cross-model conditions** — a rule can require two models to both satisfy conditions:

```php
Kronos::rule('payroll_auto_schedule')
    ->when(PayrollConfig::class, fn ($c) => $c->auto_schedule === true)
    ->andWhen(CompanySettings::class, fn ($s) => $s->subscription_active === true)
    ->produces(fn ($config) => [
        'command'         => 'payroll:process',
        'cron_expression' => $config->cron_expression,
    ]);
```

**How debouncing works:** When any rule matches, Kronos dispatches `RebuildKronosConfig` — a `ShouldBeUnique` job. If fifty model saves fire in rapid succession, only one rebuild executes. This prevents write storms.

---

### Config Writer (YAML + Redis)

Every rule match and workflow registration ultimately writes to two places:

**`storage/kronos.yaml`** — human-readable, version-controllable source of truth:

```yaml
version: 1
generated_at: '2026-06-08T10:45:00+10:00'

schedules:
  - id: 12
    command: 'reports:generate --monthly'
    cron_expression: '0 9 1 * *'
    timezone: Pacific/Port_Moresby
    without_overlapping: true
    on_one_server: true
    enabled: true

workflows:
  - id: 1
    name: monthly_payroll
    trigger:
      type: cron
      cron_expression: '0 0 1 * *'
      timezone: Pacific/Port_Moresby
    steps:
      - name: validate_contributions
        job: App\Jobs\ValidateContributionsJob
        depends_on: []
        retries: 3
        timeout: 300
      - name: generate_statements
        job: App\Jobs\GenerateMemberStatementsJob
        depends_on: [validate_contributions]
        timeout: 600
      - name: notify_stakeholders
        job: App\Jobs\NotifyStakeholdersJob
        depends_on: [generate_statements]
        retries: 2
```

**Redis** (`kronos:config`) — fast key-value store read at every kernel boot. Preferred over the YAML file on multi-node setups. When the YAML is written, Redis is updated atomically and a pub/sub invalidation is broadcast to all nodes.

The YAML file is written using a **rename-after-write** strategy (write to `.tmp`, then `rename()`), guaranteeing no partial reads during kernel boot.

---

### Workflow Orchestration

A workflow is a named DAG (Directed Acyclic Graph) of steps with a trigger, optional branching, and shared context.

```php
Kronos::workflow('data_ingestion')
    ->trigger()->onEvent(\App\Events\DataFileUploaded::class)

    ->step('validate_file')
        ->run(\App\Jobs\ValidateUploadedFileJob::class)
        ->retries(2)
        ->timeout(120)

    ->step('parse_records')
        ->run(\App\Jobs\ParseRecordsJob::class)
        ->after('validate_file')
        ->timeout(300)

    ->parallel(
        step('notify_ops')->run(\App\Jobs\NotifyOpsJob::class),
        step('update_dashboard')->run(\App\Jobs\UpdateDashboardJob::class),
    )
    ->after('parse_records')   // both parallel steps depend on parse_records

    ->step('finalize')
        ->run(\App\Jobs\FinalizeIngestionJob::class)
        ->after('notify_ops', 'update_dashboard')

    ->onSuccess(fn () => \Log::info('Data ingestion complete'))
    ->onFailure(fn () => \Slack::send('#ops', 'Data ingestion failed'))
    ->register();
```

Each `->register()` call persists the workflow to the `kronos_workflows` table and triggers a config rebuild.

---

### DAG Resolution

Kronos resolves step execution order using **Kahn's topological sort algorithm**. Steps with no unmet dependencies are dispatched as a batch (running in parallel via the queue). When a batch completes, the resolver re-evaluates and dispatches the next ready batch.

```
Steps:        A → C → E
              B ↗   ↘ F
                      G

Batch 1:  [A, B]          (no dependencies)
Batch 2:  [C]             (depends on A and B — waits for both)
Batch 3:  [E]             (depends on C)
Batch 4:  [F, G]          (both depend on E — run in parallel)
```

If a circular dependency is detected, Kronos throws `KronosDeadlockException` at registration time — not at runtime.

---

### Workflow Context

`WorkflowContext` is a persistent key-value store shared across all steps in a run. It is backed by the `kronos_workflow_runs.context` JSON column and survives queue worker restarts and container crashes.

```php
class ParseRecordsJob implements KronosStep
{
    public function handle(WorkflowContext $context): array
    {
        // Read data written by the previous step
        $filePath = $context->get('validated_file_path');

        $records = RecordParser::parse($filePath);

        // Write for downstream steps
        $context->set('record_count', count($records));
        $context->set('parse_errors', $records->errors());

        return ['parsed' => count($records)];
    }
}
```

**Available methods:**

```php
$context->get('key', $default);    // Read a value
$context->set('key', $value);      // Write and persist immediately
$context->has('key');              // Check existence
$context->forget('key');           // Remove a key
$context->merge(['a' => 1, ...]);  // Bulk write
$context->all();                   // Get all data
```

---

### Triggers

Every workflow has exactly one trigger. Available trigger types:

| Type | Description | Example |
|---|---|---|
| `cron` | Time-based cron schedule | `->trigger()->cron('0 9 * * 1-5')` |
| `manual` | API / Artisan only | `->trigger()->manual()` |
| `model_event` | Eloquent model event | `->trigger()->onModelEvent(Invoice::class, 'created')` |
| `laravel_event` | Application event | `->trigger()->onEvent(PayrollClosed::class)` |
| `webhook` | Inbound HTTP POST | `->trigger()->webhook('/kronos/trigger/payroll')` |
| `after_workflow` | On completion of another workflow | `->trigger()->afterWorkflow('data_ingestion')` |

**Cron with timezone:**

```php
->trigger()
    ->cron('0 0 1 * *')
    ->timezone('Pacific/Port_Moresby')
```

---

### Branching

Workflows can define conditional branches evaluated at runtime against the workflow context:

```php
Kronos::workflow('contribution_processing')
    ->trigger()->cron('0 2 * * *')

    ->step('check_threshold')
        ->run(CheckContributionThresholdJob::class)

    ->branch()
        ->when(fn ($ctx) => $ctx->get('threshold_met') === true)
            ->step('full_processing')->run(FullProcessingJob::class)->endArm()
        ->otherwise()
            ->step('partial_processing')->run(PartialProcessingJob::class)->endArm()
    ->endBranch()

    ->step('finalise')
        ->run(FinaliseJob::class)
    ->register();
```

The branch arm that does not match has all its steps marked as `skipped` — visible in the run history.

---

## Building Steps

Every step must implement `ZuqongTech\Kronos\Contracts\KronosStep`:

```php
<?php

namespace App\Jobs;

use ZuqongTech\Kronos\Contracts\KronosStep;
use ZuqongTech\Kronos\DAG\WorkflowContext;

class SendPayrollNotificationsJob implements KronosStep
{
    /**
     * Execute this step.
     *
     * @return array|null  Return an array to store as step output, or null.
     */
    public function handle(WorkflowContext $context): array|null
    {
        $count = $context->get('record_count', 0);

        Notification::send(
            User::role('payroll-admin')->get(),
            new PayrollCompletedNotification($count)
        );

        return ['notified_count' => User::role('payroll-admin')->count()];
    }
}
```

**Step options on the definition:**

```php
->step('my_step')
    ->run(MyStepJob::class, ['param' => 'value'])  // constructor params
    ->after('upstream_step')                        // dependency
    ->retries(3, delaySeconds: 60)                  // retry 3x, 60s backoff
    ->timeout(300)                                  // 5 minute timeout
    ->parallel()                                    // hint: can run in parallel
    ->skipUnless('context_key')                     // skip if context key is falsy
    ->onSuccess(fn () => Log::info('...'))
    ->onFailure(fn () => Slack::send('#alerts', '...'))
```

---

## Scheduled Tasks (Simple Cron)

For simple scheduled commands without multi-step workflows, use the `KronosScheduledTask` model directly or via the Filament UI:

```php
use ZuqongTech\Kronos\Models\KronosScheduledTask;

KronosScheduledTask::create([
    'name'                => 'clear_expired_sessions',
    'command'             => 'session:gc',
    'cron_expression'     => '0 3 * * *',
    'timezone'            => 'UTC',
    'enabled'             => true,
    'without_overlapping' => true,
    'on_one_server'       => true,
    'run_in_background'   => true,
]);
```

Or use the rule engine to derive tasks from your own models:

```php
Kronos::rule('enable_report_task')
    ->when(ReportSchedule::class, fn ($r) => $r->active && $r->cron !== null)
    ->produces(fn ($r) => [
        'id'              => $r->id,
        'command'         => "reports:generate --id={$r->id}",
        'cron_expression' => $r->cron,
        'timezone'        => $r->timezone,
        'enabled'         => true,
    ]);
```

---

## Multi-Node Deployments

### Enable multi-node mode

```bash
# .env
KRONOS_MULTI_NODE=true
KRONOS_REDIS_CONNECTION=default
```

When `KRONOS_MULTI_NODE=true`:

- All schedule entries are registered with `->onOneServer()` automatically.
- The orchestrator wraps every `advance()` call in a Redis distributed lock.
- `ExecuteWorkflowStep` uses `ShouldBeUnique` — a step can only execute once per run regardless of node count.
- Config is always read from Redis first, with `kronos.yaml` as fallback.
- Config writes broadcast a Redis pub/sub invalidation on the `kronos:invalidate` channel.

### Recommended ECS / Kubernetes setup

Run a **single dedicated scheduler replica** that only runs `schedule:run`, separate from your web/worker replicas:

```yaml
# docker-compose.yml (simplified)
services:
  app:
    image: your-app
    replicas: 3

  kronos-scheduler:
    image: your-app
    command: ["php", "artisan", "schedule:work"]
    deploy:
      replicas: 1   # Always exactly one — Kronos handles this
```

This eliminates multi-node scheduler overlap at the infrastructure level, leaving Redis locking as a defence-in-depth layer only.

### Write-path flow on multi-node

```
Any Node: Model saved
    │
    ▼ Observer fires
    │
    ▼ KronosRuleEngine::evaluate()
    │
    ▼ RebuildKronosConfig::dispatch()  ← ShouldBeUnique, collapses duplicates
    │
    ▼ Queue Worker (single execution)
    │
    ▼ KronosConfigWriter::rebuildFromDatabase()
    │   ├── Writes kronos.yaml  (atomic rename)
    │   └── Redis::set('kronos:config', ...)
    │       └── Redis::publish('kronos:invalidate', ...)
    │
    ▼ All nodes receive pub/sub — local caches invalidated
```

---

## Filament UI

Kronos ships a first-class [Filament v3](https://filamentphp.com) plugin.

### Register the plugin

```php
// app/Providers/Filament/AdminPanelProvider.php

use ZuqongTech\Kronos\Filament\KronosPlugin;

->plugins([
    KronosPlugin::make(),
])
```

### Available UI screens

| Screen | Description |
|---|---|
| **Kronos Dashboard** | Live stats — running workflows, today's failures, completion counts |
| **Workflows** | Create, edit, enable/disable, and manually trigger workflows |
| **Scheduled Tasks** | CRUD for simple cron tasks; changes rebuild `kronos.yaml` automatically |
| **Run History** | Per-run status, step timeline, context inspector, exception viewer |

> **Note:** The Filament plugin requires `filament/filament: ^3.0` in your application's `composer.json`. It is suggested but not required by Kronos itself.

---

## Webhook API

Enable the HTTP webhook endpoint for external workflow triggers:

```bash
# .env
KRONOS_WEBHOOK_ENABLED=true
KRONOS_WEBHOOK_SECRET=your-secret-token
KRONOS_WEBHOOK_PREFIX=kronos
```

### Trigger a workflow

```http
POST /kronos/trigger/{workflow}
X-Kronos-Secret: your-secret-token
Content-Type: application/json

{
    "context": {
        "initiated_by": "external-system",
        "batch_id": "abc123"
    }
}
```

**Response:**

```json
{
    "message": "Workflow triggered.",
    "run_id": "01936e4a-6b2c-7000-8000-000000000001"
}
```

### Check run status

```http
GET /kronos/runs/{run_id}
X-Kronos-Secret: your-secret-token
```

**Response:**

```json
{
    "run_id": "01936e4a-6b2c-7000-8000-000000000001",
    "workflow": "monthly_payroll",
    "status": "running",
    "started_at": "2026-06-08T00:00:01+10:00",
    "finished_at": null,
    "duration": null,
    "context": { "validated_count": 1420 },
    "steps": [
        { "name": "validate_contributions", "status": "completed", "attempt": 1, "duration": 14 },
        { "name": "generate_statements",    "status": "running",   "attempt": 1, "duration": null },
        { "name": "notify_stakeholders",    "status": "pending",   "attempt": 1, "duration": null }
    ]
}
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `kronos:install` | Publish config, run migrations |
| `kronos:list` | List all workflows and scheduled tasks |
| `kronos:trigger {workflow}` | Manually trigger a workflow by name |
| `kronos:trigger {workflow} --context='{"key":"val"}'` | Trigger with JSON context |
| `kronos:status` | Show recent workflow run history |
| `kronos:status {run_id}` | Show step-level detail for a specific run |
| `kronos:rebuild` | Force a full config rebuild from DB → YAML + Redis |

---

## Events

Kronos fires standard Laravel events you can listen to:

```php
use ZuqongTech\Kronos\Events\WorkflowCompleted;
use ZuqongTech\Kronos\Events\WorkflowFailed;
use ZuqongTech\Kronos\Events\WorkflowStepCompleted;
use ZuqongTech\Kronos\Events\WorkflowStepFailed;
```

Register listeners in your `EventServiceProvider`:

```php
protected $listen = [
    WorkflowCompleted::class     => [SendWorkflowCompletionSlack::class],
    WorkflowFailed::class        => [AlertOpsTeam::class],
    WorkflowStepCompleted::class => [UpdateProgressDashboard::class],
    WorkflowStepFailed::class    => [LogStepFailure::class],
];
```

**Payload:**

```php
// WorkflowCompleted / WorkflowFailed
$event->run;          // KronosWorkflowRun model

// WorkflowFailed
$event->reason;       // string error message

// WorkflowStepCompleted / WorkflowStepFailed
$event->run;          // KronosWorkflowRun model
$event->stepName;     // string

// WorkflowStepFailed
$event->error;        // string exception message
```

---

## Configuration Reference

```php
// config/kronos.php

return [

    // Path to the canonical YAML config file
    'config_path' => storage_path('kronos.yaml'),

    // Enable multi-node / distributed mode
    // Sets onOneServer() on all entries and uses Redis as primary config store
    'multi_node' => env('KRONOS_MULTI_NODE', false),

    // Redis connection name (from config/database.php)
    'redis_connection' => env('KRONOS_REDIS_CONNECTION', 'default'),

    // Queue connection and name for Kronos internal jobs
    'queue' => [
        'connection' => env('KRONOS_QUEUE_CONNECTION', 'redis'),
        'name'       => env('KRONOS_QUEUE_NAME', 'kronos'),
    ],

    // Inbound webhook trigger endpoint
    'webhook' => [
        'enabled' => env('KRONOS_WEBHOOK_ENABLED', false),
        'secret'  => env('KRONOS_WEBHOOK_SECRET'),
        'prefix'  => env('KRONOS_WEBHOOK_PREFIX', 'kronos'),
    ],

    // Run history retention in days (null = keep forever)
    'retention_days' => env('KRONOS_RETENTION_DAYS', 30),

    // Filament UI plugin settings
    'filament' => [
        'enabled'   => env('KRONOS_FILAMENT_ENABLED', true),
        'panel_id'  => env('KRONOS_FILAMENT_PANEL', 'admin'),
        'nav_group' => 'Kronos',
        'nav_sort'  => 90,
    ],

    // Default timezone for all schedules
    'timezone' => env('KRONOS_TIMEZONE', 'UTC'),
];
```

**Environment variables summary:**

| Variable | Default | Description |
|---|---|---|
| `KRONOS_MULTI_NODE` | `false` | Enable distributed multi-node mode |
| `KRONOS_REDIS_CONNECTION` | `default` | Redis connection for locking + config |
| `KRONOS_QUEUE_CONNECTION` | `redis` | Queue connection for Kronos jobs |
| `KRONOS_QUEUE_NAME` | `kronos` | Queue name for Kronos jobs |
| `KRONOS_WEBHOOK_ENABLED` | `false` | Enable HTTP webhook endpoint |
| `KRONOS_WEBHOOK_SECRET` | — | Shared secret for webhook auth |
| `KRONOS_WEBHOOK_PREFIX` | `kronos` | URL prefix for webhook routes |
| `KRONOS_RETENTION_DAYS` | `30` | Days to keep run history |
| `KRONOS_TIMEZONE` | `UTC` | Default schedule timezone |

---

## Testing

Kronos is tested with [PestPHP](https://pestphp.com).

```bash
# Run all tests
./vendor/bin/pest

# Run with coverage
./vendor/bin/pest --coverage

# Run only unit tests
./vendor/bin/pest --testsuite=Unit

# Run only feature tests
./vendor/bin/pest --testsuite=Feature
```

### Testing workflows in your application

Kronos exposes Laravel's standard `Bus::fake()` and `Event::fake()` patterns cleanly:

```php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use ZuqongTech\Kronos\Events\WorkflowCompleted;
use ZuqongTech\Kronos\Jobs\ExecuteWorkflowStep;

it('triggers the monthly payroll workflow', function () {
    Bus::fake();
    Event::fake();

    $runId = Kronos::trigger('monthly_payroll');

    Bus::assertDispatched(ExecuteWorkflowStep::class, fn ($job) =>
        $job->stepName === 'validate_contributions'
    );
});

it('fires WorkflowCompleted on success', function () {
    Event::fake();

    // Simulate a completed run
    $run = KronosWorkflowRun::factory()->completed()->create();
    event(new WorkflowCompleted($run));

    Event::assertDispatched(WorkflowCompleted::class);
});
```

### Testing your step jobs directly

Step jobs are plain PHP classes — test them in isolation without queue infrastructure:

```php
it('writes validated_count to context', function () {
    $run = KronosWorkflowRun::factory()->create(['context' => []]);
    $context = new WorkflowContext($run);

    $job = new ValidateContributionsJob();
    $output = $job->handle($context);

    expect($output)->toHaveKey('count')
        ->and($context->get('validated_count'))->toBeInt();
});
```

---

## FAQ

**Q: Does Kronos replace Laravel Horizon?**

No — Kronos uses Horizon (or any queue driver) to dispatch step jobs. Kronos handles *orchestration* (which jobs run in what order and when). Horizon handles *execution* (the worker pool, monitoring, retries at the queue level).

**Q: Can I use a database queue instead of Redis?**

Yes, Kronos works with any Laravel queue driver. Redis is recommended for multi-node deployments because it is also used for distributed locking and config pub/sub. For single-node or development setups, `QUEUE_CONNECTION=database` works fine.

**Q: Does Kronos work with Laravel Octane?**

Yes. The service provider uses `callAfterResolving` for schedule hydration, which is compatible with Octane's persistent process model. Ensure `KRONOS_MULTI_NODE=true` is set so config state is read from Redis rather than a process-local file cache.

**Q: Can I define workflows in a config file instead of code?**

Not currently — workflows are defined in code (service providers) and persisted to the DB. A YAML-first workflow definition format is planned for a future release.

**Q: How does Kronos prevent duplicate job execution on multi-node?**

Three layers:

1. `RebuildKronosConfig` is `ShouldBeUnique` — only one rebuild runs at a time.
2. `ExecuteWorkflowStep` is `ShouldBeUnique` per `(run_id, step_name)` — a step can only be dispatched once.
3. `KronosOrchestrator::advance()` acquires a Redis lock per run before evaluating ready steps.

**Q: Can I cancel a running workflow?**

Manual cancellation via Artisan or the Filament UI is on the roadmap. Currently you can set `status = cancelled` directly on the `KronosWorkflowRun` record — the orchestrator checks `isTerminal()` before advancing.

**Q: How are failed workflows retried?**

At the workflow level, retries are not automatic — re-triggering creates a new run. Step-level retries (the `->retries(n)` option) are automatic and use Laravel's built-in job retry mechanism.

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for a history of changes.

---

## Contributing

Contributions are very welcome. Please review [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on:

- Reporting bugs
- Suggesting features
- Submitting pull requests
- Coding standards (PSR-12 + PHPStan level 8)
- Commit message format (Conventional Commits)

---

## Security

If you discover a security vulnerability, please review [SECURITY.md](SECURITY.md) and **do not** open a public issue. Email `security@zuqongtech.com` directly.

---

## Credits

- **[Zuqong Technologies](https://zuqongtech.com)** — original author and maintainer
- All contributors who submit issues, PRs, and feedback

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for details.# laravel-kronos
