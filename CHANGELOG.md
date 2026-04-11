# Changelog

All notable changes to `laragraph` will be documented in this file.

## v0.1.6 - 2026-04-11

### Bug fixes

**`SendNode` fan-out not dispatching jobs** (`->transition()` wiring)

When a node returns `Send[]` directly from `handle()` (i.e. `SendNode`), the engine was incorrectly passing the array to `applyMutation()`, merging `Send` objects as numeric state keys `0, 1, 2...` instead of dispatching them as fan-out jobs.

`ExecuteNode` now detects when the full return value is a list of `Send` objects and uses those as the sole routing targets, bypassing edge resolution. This means `SendNode` works correctly with `->transition()` as documented — no `->branch()` workaround needed.

**`NodeExecution` model querying wrong table**

Laravel auto-derives `node_executions` from the class name, but the migration creates `workflow_node_executions`. Fixed by adding an explicit `$table` property to the model.

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
