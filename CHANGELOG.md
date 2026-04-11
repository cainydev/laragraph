# Changelog

All notable changes to `laragraph` will be documented in this file.

## v0.1.5 - 2026-04-11

### What's new

**Node execution persistence (`HasTags` + `workflow_node_executions`)**

Nodes implementing `HasTags` now have their tags automatically persisted to a new `workflow_node_executions` table after each execution — inside the same transaction as the state update. This gives you a full per-execution history queryable outside of workflow state.

```php
$run->nodeExecutions->sum(fn($e) => $e->tags['cost_usd'] ?? 0);

```
See the [HasTags docs](README.md#hastags) for the full API.

**Workflow class-string registration**

Both `Laragraph::register()` and the `workflows` config key now accept a bare class string — no closure required:

```php
Laragraph::register('my-pipeline', MyPipelineWorkflow::class);

```
The engine resolves the class via the container and calls `build()` automatically.

**Queue job display name**

`ExecuteNode` now implements `displayName()`, so Horizon and other queue dashboards show `ExecuteNode [node-name] on run [id]` instead of the bare class name.
