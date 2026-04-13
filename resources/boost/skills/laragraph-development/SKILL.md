---
name: laragraph-development
description: Build and work with LaraGraph workflows, nodes, edges, fan-out/fan-in patterns, and human-in-the-loop flows.
---

# LaraGraph Development

## When to use this skill

Use this skill when creating or modifying LaraGraph workflows, nodes, edges, or anything related to the `cainy/laragraph` package.

---

## Core concepts

- **Workflow** — a class extending `Workflow` that defines a directed graph in `definition()`.
- **Node** — any class implementing `Node`. Receives full state, returns a mutation array (only changed keys).
- **State** — a plain PHP array persisted in `workflow_runs.state`. Mutations are merged by a reducer.
- **Pointer** — internal tracking of which nodes are in-flight. One pointer is pushed per dispatched job.
- **WorkflowRun** — the DB record for a single execution. Status: `Running`, `Paused`, `Completed`, `Failed`.

Execution is fully queue-driven. Each node runs as an independent `ExecuteNode` job.

---

## Defining a workflow

```php
use Cainy\Laragraph\Builder\Workflow;

class MyPipeline extends Workflow
{
    public function definition(): void
    {
        $this->addNode('fetch',     FetchNode::class)
             ->addNode('transform', TransformNode::class)
             ->addNode('store',     StoreNode::class)
             ->transition(Workflow::START, 'fetch')
             ->transition('fetch',     'transform')
             ->transition('transform', 'store')
             ->transition('store',     Workflow::END);
    }
}
```

- Nodes can be class strings (resolved via the container) or pre-built instances.
- `Workflow::START` and `Workflow::END` are reserved pseudo-nodes.

---

## Defining a node

```php
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class SummarizeNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['summary' => substr($state['text'] ?? '', 0, 200)];
    }
}
```

Key `NodeExecutionContext` properties:
- `$context->runId` — WorkflowRun ID
- `$context->nodeName` — name of this node in the graph
- `$context->attempt` / `$context->maxAttempts`
- `$context->payload('key', $default)` — read Send-injected isolated payload
- `$context->isSendExecution()` — true when dispatched via a Send

---

## Running a workflow

```php
use Cainy\Laragraph\Facades\Laragraph;

$run = Laragraph::run(MyPipeline::class, initialState: ['input' => 'hello']);
Laragraph::pause($run->id);
Laragraph::resume($run->id, ['approved' => true]); // merges additional state
Laragraph::abort($run->id);
```

---

## Edges

```php
// Unconditional
->transition('a', 'b')

// Conditional — Closures only, no string expressions
->transition('classify', 'approve', fn(array $s) => $s['score'] > 50)
->transition('classify', 'reject',  fn(array $s) => $s['score'] <= 50)

// Branch — returns one or more node names or Send objects dynamically
->branch('router', fn(array $s): string => $s['ok'] ? 'publish' : 'revise',
    targets: ['publish', 'revise'])
```

---

## Parallel fan-out / fan-in

### Static fan-out (multiple transitions from one node)

```php
->transition('split', 'branch-a')
->transition('split', 'branch-b')
->transition('branch-a', 'merge')
->transition('branch-b', 'merge')
->addNode('merge', MergeNode::class)
```

### Dynamic fan-out with Send

```php
use Cainy\Laragraph\Routing\Send;

->branch('planner', fn(array $s) => array_map(
    fn($q) => new Send('worker', ['query' => $q]),
    $s['queries']
), targets: ['worker'])
->transition('worker', 'barrier')
```

Or use the prebuilt `SendNode`:

```php
use Cainy\Laragraph\Nodes\SendNode;

->addNode('fanout', new SendNode(sourceKey: 'queries', targetNode: 'worker', payloadKey: 'query'))
->addNode('worker', WorkerNode::class)
->transition(Workflow::START, 'fanout')
->transition('fanout', 'worker')
->transition('worker', 'barrier')
```

### Fan-in with ReduceNode — choosing the right pattern

**IMPORTANT: picking the wrong pattern causes double-fire bugs.**

**Pattern 1 — Pointer-only (recommended, always safe)**

Fires when the last dispatched worker finishes. Completely independent of what workers wrote to state. Use this by default, and always when a worker may push a variable number of items.

```php
->addNode('barrier', new ReduceNode(collectKey: 'results'))
// No expectedCount / countFromKey → state check bypassed, fires on last pointer.
```

**Pattern 2 — State-content count (use with care)**

Fires when `count(state['results']) >= N`. Only safe when every worker pushes **exactly one item**. If any worker can push more than one item, the barrier may fire before all workers are done.

```php
->addNode('barrier', new ReduceNode(collectKey: 'results', expectedCount: 3))
// Or dynamic:
->addNode('barrier', new ReduceNode(collectKey: 'results', countFromKey: 'query_count'))
```

**Pattern 3 — Both guards**

Last pointer AND state count must both be satisfied. Still requires each worker to push exactly one item.

```php
->addNode('barrier', new ReduceNode(collectKey: 'results', countFromKey: 'worker_count'))
```

> Never point `countFromKey` at a count of worker *outputs* if a single worker can produce multiple outputs. Use Pattern 1 instead.

---

## Human-in-the-loop

```php
// Pause before a node (node runs on resume)
->interruptBefore('review')

// Pause after a node (edges evaluated on resume)
->interruptAfter('drafter')

// Dynamic pause from inside a node
throw new NodePausedException($context->nodeName, stateMutation: ['reason' => 'low confidence']);

// Resume from outside
Laragraph::resume($run->id, ['approved' => true]);
```

Use `GateNode` for a static unconditional pause:

```php
->addNode('approve', new GateNode(reason: 'Manager approval required'))
```

---

## State and reducers

| Reducer | Behaviour |
|---|---|
| `SmartReducer` *(default)* | Lists append, scalars and associative arrays overwrite |
| `MergeReducer` | Deep recursive merge |
| `OverwriteReducer` | Shallow `array_merge` always |

Set per-workflow: `$this->withReducer(MyReducer::class)`

Set globally in a service provider: `$this->app->bind(StateReducerInterface::class, MyReducer::class)`

---

## Node contracts (optional interfaces)

| Contract | Purpose |
|---|---|
| `HasName` | Stable `name(): string` for routing/visualization |
| `HasTags` | Emit metadata (model, tokens, cost) per execution |
| `HasRetryPolicy` | Custom backoff with `RetryPolicy` |
| `HasQueue` | Route node job to a specific queue/connection |
| `HasMiddleware` | Attach Laravel job middleware (e.g. `RateLimited`) |
| `HasLoop` | Declare a loop sub-node and condition; compiler injects edges |
| `IsFanInBarrier` | Mark a custom node as a fan-in barrier (like `ReduceNode`) |

Example — retry policy:

```php
use Cainy\Laragraph\Engine\RetryPolicy;

public function retryPolicy(): RetryPolicy
{
    return new RetryPolicy(
        initialInterval: 1.0,
        backoffFactor:   2.0,
        maxInterval:     30.0,
        maxAttempts:     5,
        jitter:          true,
        retryOn:         [RateLimitException::class],
    );
}
```

---

## Built-in nodes

| Node | Use |
|---|---|
| `GateNode(reason:)` | Unconditional pause for human approval |
| `SendNode(sourceKey:, targetNode:, payloadKey:)` | Fan-out over a state list |
| `ReduceNode(collectKey:, expectedCount:, countFromKey:)` | Fan-in barrier |
| `DelayNode(seconds:)` | Pause for N seconds; auto-resumes via queued job — no CRON needed |
| `HttpNode(url:, method:, headers:, bodyKey:, responseKey:)` | HTTP request; URL supports `{state.key}` interpolation |
| `CacheNode(operation:, cacheKey:, stateKey:, ttl:)` | Cache get/put/forget; key supports `{state.key}` interpolation |
| `NotifyNode(event:, dataKeys:)` | Dispatch a Laravel event from state values |

---

## Sub-graph workflows

Any `Workflow` subclass can be embedded as a node. The engine pauses the parent, runs the child to completion, then resumes the parent automatically:

```php
->addNode('research', ResearchSubWorkflow::class)
```

---

## Lifecycle hooks

Override on your `Workflow` subclass (exceptions are swallowed, never affect engine state):

```php
public function onStarting(WorkflowRun $run): void {}
public function onCompleted(WorkflowRun $run): void {}
public function onFailed(WorkflowRun $run, Throwable $exception): void {}
```

---

## Events dispatched by the engine

`WorkflowStarted`, `NodeExecuting`, `NodeCompleted`, `NodeFailed`, `WorkflowCompleted`, `WorkflowFailed`, `WorkflowResumed`, `HumanInterventionRequired`

---

## Testing

Use `Queue::fake()` and the sync driver. The package runs migrations against SQLite in-memory in tests:

```php
$run = Laragraph::run(MyPipeline::class, ['input' => 'test']);
expect($run->fresh()->status)->toBe(RunStatus::Completed);
expect($run->fresh()->state['output'])->toBe('expected');
```
