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
- **State** — a plain PHP array persisted in `workflow_runs.state`. Mutations are merged by a reducer. Contains **only** user-authored keys.
- **Routing** — engine-managed metadata persisted in `workflow_runs.routing`. Holds spawn counters, interrupt markers, gate reason, child-run bookkeeping, last error summary. Read-only from nodes via `$context->routing`. Never merge engine data into `$state`.
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
- `$context->workflowKey` — workflow class FQCN (same as `$run->key`)
- `$context->nodeName` — name of this node in the graph
- `$context->attempt` / `$context->maxAttempts`
- `$context->payload('key', $default)` — read Send-injected isolated payload
- `$context->isSendExecution()` — true when dispatched via a Send
- `$context->parentRunId` / `$context->parentNodeName` — set when this run is a child workflow
- `$context->parentMetadata()` — lazy-loads parent run metadata (null at top level)
- `$context->routing` — read-only engine routing snapshot

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

// Conditional — Closures only
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

### Fan-in with BarrierNode

Drop a `BarrierNode` anywhere parallel branches converge. No configuration needed — the engine tracks dispatch and completion counts automatically.

```php
use Cainy\Laragraph\Nodes\BarrierNode;

->addNode('barrier', new BarrierNode())
->transition('worker', 'barrier')   // one or many workers feeding in
->transition('barrier', 'next')
```

Works with transition fan-out, `Send`-based fan-out, and sequential back-to-back barriers. Early arrivals skip cleanly; the final arrival (all predecessors complete) is the only one that fires the downstream edge.

---

## Fan-out patterns (pick the right shape)

### 1. One-shot fan-out → barrier

Single worker node per item, no per-item downstream steps.

```php
->branch('split', fn($s) => array_map(fn($id) => new Send('worker', ['id' => $id]), $s['ids']), ['worker'])
->transition('worker', 'barrier')
->transition('barrier', 'aggregate')
```

### 2. Per-item pipeline → child workflow → barrier

When each item needs a multi-step pipeline (enrich → qualify → classify),
**make the pipeline its own `Workflow`** and dispatch via
`Send::toWorkflow()`. Do NOT try to chain sibling nodes in the parent graph —
`Send` payloads don't propagate through subsequent static edges.

```php
class LeadPipeline extends Workflow
{
    public function definition(): void
    {
        $this->addNode('enrich',   EnrichNode::class)
             ->addNode('qualify',  QualifyNode::class)
             ->transition(Workflow::START, 'enrich')
             ->transition('enrich',  'qualify')
             ->transition('qualify', Workflow::END);
    }
}

// Parent fans out:
->addNode('per_lead', app(LeadPipeline::class))
->addNode('barrier',  new BarrierNode())
->branch(Workflow::START, fn($s) => collect($s['lead_ids'])->map(
    fn($id) => Send::toWorkflow('per_lead', ['lead_id' => $id])
)->all(), ['per_lead'])
->transition('per_lead', 'barrier')
```

`Send::toWorkflow($nodeName, $payload)` seeds the child with the payload as
its initial state. Parent state does NOT leak into the child. Each Send
spawns an isolated child run.

### 3. Parent supervises children (partial-failure tolerance)

Override `shouldCascadeFailure()` on the child when one child's failure
shouldn't fail the whole run.

```php
class ToleratingChild extends Workflow
{
    public function shouldCascadeFailure(): bool { return false; }
    public function definition(): void { /* ... */ }
}
```

---

## Human-in-the-loop

```php
// Pause before a node (node runs on resume)
->interruptBefore('review')

// Pause after a node (edges evaluated on resume)
->interruptAfter('drafter')

// Dynamic pause from inside a node — gateReason rides on routing, NOT state
throw new NodePausedException(
    nodeName:      $context->nodeName,
    stateMutation: ['draft_attempt' => ($state['draft_attempt'] ?? 0) + 1],
    gateReason:    'Needs human review',
);

// Resume from outside
Laragraph::resume($run->id, ['approved' => true]);
```

Read the reason on the paused run via `$run->routing['gate_reason']`.

`GateNode` is a one-shot pause — its `handle()` unconditionally throws. Use it for static approval checkpoints where the node itself has no side effects:

```php
->addNode('approve', new GateNode(reason: 'Manager approval required'))
```

For human-in-the-loop where the node's work should complete before pausing, prefer `interruptAfter('node')` instead.

---

## State and reducers

| Reducer | Behaviour |
|---|---|
| `SmartReducer` *(default)* | Lists append, scalars and associative arrays overwrite |
| `MergeReducer` | Deep recursive merge |
| `OverwriteReducer` | Shallow `array_merge` always |

Set per-workflow: `$this->withReducer(MyReducer::class)`

Set globally in a service provider: `$this->app->bind(StateReducerInterface::class, MyReducer::class)`

### State vs routing (important)

`$state` is for **your** data. The engine never merges its bookkeeping into
it. If you see a node doing something like
`$state['__some_internal_key']` — that's a smell; read `$context->routing`
instead, or restructure so the node doesn't need engine data.

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
| `IsFanInBarrier` | Mark a custom node as a fan-in barrier (like `BarrierNode`) |

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
| `BarrierNode()` | Fan-in barrier — waits for all parallel workers before continuing |
| `DelayNode(seconds:)` | Pause for N seconds; auto-resumes via queued job — no CRON needed |
| `HttpNode(url:, method:, headers:, bodyKey:, responseKey:)` | HTTP request; URL supports `{state.key}` interpolation |
| `CacheNode(operation:, cacheKey:, stateKey:, ttl:)` | Cache get/put/forget; key supports `{state.key}` interpolation |
| `NotifyNode(event:, dataKeys:)` | Dispatch a Laravel event from state values |

---

## Prism integration

**`PrismNode`** — general-purpose LLM node. Text mode by default (appends to `state['messages']`). Override `schema()` to switch to structured-output mode (writes to `state[outputKey()]`). Does **not** implement `HasLoop`.

```php
use Cainy\Laragraph\Integrations\Prism\PrismNode;
use Prism\Prism\Enums\Provider;

->addNode('assistant', new PrismNode(
    provider: Provider::Anthropic,
    model:    'claude-sonnet-4-6',
    systemPrompt: 'You are a helpful assistant.',
))
```

Subclass overrides: `provider()`, `model()`, `maxTokens()`, `temperature()`,
`topP()`, `systemPrompt($state)`, `prompt($state)`, `messages($state)`,
`tools()`, `schema()`, `messagesKey()`, `outputKey()`.

**`PrismToolNode`** — extends `PrismNode` and implements `HasLoop`. Use for tool-calling agents that re-enter after each tool call. Override `tools()` to supply Prism `Tool` instances.

```php
use Cainy\Laragraph\Integrations\Prism\PrismToolNode;

class WeatherAgent extends PrismToolNode
{
    public function tools(): array { return [new GetWeatherTool]; }
}
```

The compiler injects `{name}.__loop__` and routes: agent → (tool_calls) → loop → agent → (done) → next.

---

## Sub-graph workflows

Any `Workflow` subclass can be embedded as a node.

```php
->addNode('research', ResearchSubWorkflow::class)
```

### Reach modes

| Reached via | Child initial state | Delta back to parent |
|---|---|---|
| Embedded (`transition(..., 'sub')`) | Parent's full state | Diff from child's state |
| `Send::toWorkflow('sub', $payload)` | Payload only | Child's full final state |

Use `Send::toWorkflow()` for per-item pipelines. Use plain embedding when the child should see parent state.

### Child failure cascades to parent by default

When a child fails, the parent is marked `Failed` with the child's exception attached. Opt out by overriding `shouldCascadeFailure(): bool` on the child workflow subclass.

### Accessing parent metadata

```php
$context->parentRunId         // ?int
$context->parentNodeName      // ?string
$context->parentMetadata();   // ?array (lazy-loaded)
```

---

## Error observability

When a node fails (after all retries exhausted), a `workflow_node_executions`
row is written with:

- `failed_at` (timestamp, not null when this row is a failure)
- `error_class`, `error_message`, `error_trace`

The error summary is also stored on the run's routing column at
`$run->routing['error']` (fields: `node`, `class`, `message`, `file`, `line`,
optional `from_child` when cascaded). **Not** on `$run->state`.

Query patterns:

```php
$run->nodeExecutions->filter(fn($e) => $e->failed());
$run->routing['error']['class'] ?? null;
```

---

## Recursion limit

Default: `config('laragraph.recursion_limit', 100)`. Override per-workflow when doing large fan-outs:

```php
$this->withRecursionLimit(500);
```

Size it as roughly `items × steps-per-item + headroom`. The exception hints at this in its message.

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

For Prism-based tests, use `Prism::fake([TextResponseFake::make()->withText('...')])` or `StructuredResponseFake` for schema mode.

---

## Common pitfalls

1. **Payload doesn't propagate past the first static edge.** A `Send` gives
   its target an isolated payload, but the next transition dispatches with
   `payload = null`. If you need a per-item pipeline, use `Send::toWorkflow()`
   to a child workflow instead of chaining sibling nodes.
2. **Engine keys in state.** Don't read `$state['__interrupt']` or
   `$state['gate_reason']` — those moved to the `routing` column. Use
   `$context->routing['interrupt']`, `$run->routing['gate_reason']`, etc.
3. **Recursion limit on fan-out.** The default 100 is fine for most
   workflows but fan-outs with ≥50 items doing ≥3 steps each will trip it.
   Call `->withRecursionLimit()` with room to spare.
4. **Anonymous workflow subclasses with constructor args.** Can't be resolved
   by `app(FQCN)`; use named top-level classes for sub-workflows.
