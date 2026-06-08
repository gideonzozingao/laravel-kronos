# Changelog

All notable changes to `laravel-kronos` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial package scaffold with full DAG-based workflow orchestration engine
- Rule engine — maps Eloquent model events to reactive config writes
- Canonical `kronos.yaml` + Redis dual-store config system
- `WorkflowDefinition` fluent builder with `step()`, `parallel()`, `branch()` API
- `TriggerDefinition` supporting cron, model event, Laravel event, webhook, manual, and workflow-completion triggers
- `DAGResolver` using Kahn's algorithm for topological sort and deadlock detection
- `WorkflowContext` — persistent inter-step shared state backed by the DB
- `KronosOrchestrator` — distributed-safe execution engine with Redis locking
- `ExecuteWorkflowStep` job with `ShouldBeUnique` guard and retry support
- `RebuildKronosConfig` debounced unique job for reactive config writes
- Multi-node safety: `onOneServer()`, Redis pub/sub invalidation, `ShouldBeUnique` step dispatch
- Filament v3 plugin: `KronosDashboard`, `KronosWorkflowResource`, `KronosScheduledTaskResource`
- Webhook HTTP endpoint for external workflow triggers
- Five Artisan commands: `kronos:install`, `kronos:list`, `kronos:trigger`, `kronos:rebuild`, `kronos:status`
- Full migration suite: `kronos_workflows`, `kronos_workflow_runs`, `kronos_step_runs`, `kronos_scheduled_tasks`, `kronos_schedule_runs`
- PestPHP test suite covering DAG resolution, rule engine, and orchestrator

## [0.1.0] - TBD

_First stable release._