<div align="left">
  <img src="resources/images/laragraph_logo.svg" alt="LaraGraph" width="120" />
  <h1>LaraGraph</h1>

  [![Latest Version on Packagist](https://img.shields.io/packagist/v/cainydev/laragraph.svg?style=flat-square)](https://packagist.org/packages/cainydev/laragraph)
  [![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/cainydev/laragraph/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cainydev/laragraph/actions?query=workflow%3Arun-tests+branch%3Amain)
  [![Total Downloads](https://img.shields.io/packagist/dt/cainydev/laragraph.svg?style=flat-square)](https://packagist.org/packages/cainydev/laragraph)

  <p>Stateful, graph-based workflow engine for Laravel.<br>Build multi-step agent pipelines, human-in-the-loop processes, and parallel fan-out/fan-in tasks — all backed by your database and queue.</p>

  <sub>Inspired by <a href="https://github.com/langchain-ai/langgraph">LangGraph</a></sub>
</div>

## Table of Contents

- [Installation](#installation)
- [Core Concepts](#core-concepts)
- [Building a Workflow](#building-a-workflow)
  - [Nodes](#nodes)
  - [Transitions](#transitions)
  - [Conditional Edges](#conditional-edges)
  - [Branch Edges](#branch-edges)
  - [Parallel Branches](#parallel-branches)
  - [Dynamic Fan-out with Send](#dynamic-fan-out-with-send)
- [Running a Workflow](#running-a-workflow)
  - [Registering Workflows](#registering-workflows)
  - [Starting a Run](#starting-a-run)
  - [Starting from a Blueprint](#starting-from-a-blueprint)
  - [Controlling a Run](#controlling-a-run)
- [State](#state)
  - [Reducers](#reducers)
  - [Custom Reducer](#custom-reducer)
- [Human-in-the-Loop](#human-in-the-loop)
  - [interrupt_before](#interrupt_before)
  - [interrupt_after](#interrupt_after)
  - [Resuming](#resuming)
  - [Dynamic Pause from a Node](#dynamic-pause-from-a-node)
- [Node Contracts](#node-contracts)
  - [HasName](#hasname)
  - [HasTags](#hastags)
  - [HasRetryPolicy](#hasretrypolicy)
  - [HasLoop](#hasloop)
  - [SerializableNode](#serializablenode)
- [Built-in Nodes](#built-in-nodes)
  - [GateNode](#gatenode)
  - [SendNode](#sendnode)
  - [ReduceNode](#reducenode)
  - [HttpNode](#httpnode)
  - [DelayNode](#delaynode)
  - [CacheNode](#cachenode)
  - [NotifyNode](#notifynode)
- [Prism Integration](#prism-integration)
  - [PrismNode](#prismnode)
  - [ToolNode](#toolnode)
  - [Automatic Tool Loops](#automatic-tool-loops)
  - [Manual Tool Routing](#manual-tool-routing)
- [Laravel AI Integration](#laravel-ai-integration)
  - [AsGraphNode Trait](#asgraphnode-trait)
  - [Structured Output](#structured-output)
  - [Tool-Using Agents](#tool-using-agents)
- [Sub-graph Workflows](#sub-graph-workflows)
- [Recursion Limit](#recursion-limit)
- [Serializable Workflows](#serializable-workflows)
- [Events](#events)
- [Configuration](#configuration)
- [Testing](#testing)

---

## Installation

```bash
composer require cainy/laragraph
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="laragraph-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laragraph-config"
```

---

## Core Concepts

LaraGraph models a workflow as a **directed graph** of nodes connected by edges. Each run of that graph is a `WorkflowRun` — a database record that tracks the current state, status, and active node pointers.

| Term | Meaning |
|---|---|
| **Node** | A unit of work. Receives the current state, returns a mutation. |
| **Edge** | A directed connection between two nodes, optionally conditional. |
| **State** | A plain PHP array that accumulates mutations as nodes execute. |
| **Pointer** | Tracks which nodes are currently in-flight for a run. |
| **WorkflowRun** | The persisted record for a single execution of a workflow. |

Execution is fully queue-driven. Each node runs as an independent `ExecuteNode` job, so parallel branches execute concurrently across your worker pool.

---

## Building a Workflow

### Nodes

A node is any class implementing `Cainy\Laragraph\Contracts\Node`:

```php
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class SummarizeNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        $text = implode("\n", $state['paragraphs'] ?? []);

        return ['summary' => substr($text, 0, 200)];
    }
}
```

`handle()` receives a typed `NodeExecutionContext` and the current full state. It returns an array of **mutations** — only the keys you want to change.

#### NodeExecutionContext

```php
$context->runId           // int    — ID of the WorkflowRun
$context->workflowKey     // string — registered name or key of the workflow
$context->nodeName        // string — name of this node in the graph
$context->attempt         // int    — current queue attempt (1-based)
$context->maxAttempts     // int    — maximum attempts configured
$context->createdAt       // DateTimeImmutable
$context->isolatedPayload // ?array — payload injected by a Send (see Dynamic Fan-out)
```

### Transitions

Build a workflow with the fluent `Workflow` builder:

```php
use Cainy\Laragraph\Builder\Workflow;

$workflow = Workflow::create()
    ->addNode('fetch',     FetchNode::class)
    ->addNode('transform', TransformNode::class)
    ->addNode('store',     StoreNode::class)
    ->transition(Workflow::START, 'fetch')
    ->transition('fetch',     'transform')
    ->transition('transform', 'store')
    ->transition('store',     Workflow::END);
```

`Workflow::START` and `Workflow::END` are reserved entry and exit pseudo-nodes.

Nodes can be registered as class strings (resolved via the container) or as pre-built instances.

### Conditional Edges

Pass a condition as the third argument to `->transition()`. It can be a **Closure** or a **Symfony Expression Language string**:

```php
// Closure
->transition('classify', 'approve', fn(array $state) => $state['score'] > 50)
->transition('classify', 'reject',  fn(array $state) => $state['score'] <= 50)

// Expression string (serializable — required for snapshot workflows)
->transition('classify', 'approve', "state['score'] > 50")
->transition('classify', 'reject',  "state['score'] <= 50")
```

The expression receives the full state under the `state` variable.

**Built-in expression functions:**

| Function | Description |
|---|---|
| `last(array)` | Last element of an array, or `null` if empty. |
| `first(array)` | First element of an array, or `null` if empty. |
| `count(array)` | Number of elements. |
| `empty(value)` | `true` if null, `[]`, `""`, or `false`. |
| `not_empty(value)` | Negation of `empty`. |
| `get(array, path, default)` | Dot-notation safe access: `get(state, "meta.score", 0)`. |
| `has_value(array, value)` | `true` if value exists in array. |
| `keys(array)` | Array keys. |
| `sum(array)` | Sum of numeric values. |
| `join(array, sep)` | Implode array with separator. |
| `send(node, items, key)` | Returns a `Send[]` array for dynamic fan-out (see below). |

### Branch Edges

A `branch` edge uses a resolver to return one or more target node names dynamically at runtime:

```php
->branch('router', function(array $state): string {
    return $state['approved'] ? 'publish' : 'revise';
}, targets: ['publish', 'revise'])
```

The `targets` array is optional but recommended — it enables graph visualization without executing the resolver.

For serializable workflows, use an expression string as the resolver:

```php
->branch('router', "state['approved'] ? 'publish' : 'revise'", targets: ['publish', 'revise'])
```

### Parallel Branches

To execute multiple nodes in parallel from a single node, add multiple transitions from the same source:

```php
Workflow::create()
    ->addNode('split',    SplitNode::class)
    ->addNode('branch-a', BranchANode::class)
    ->addNode('branch-b', BranchBNode::class)
    ->addNode('merge',    MergeNode::class)
    ->transition(Workflow::START, 'split')
    ->transition('split', 'branch-a')
    ->transition('split', 'branch-b')
    ->transition('branch-a', 'merge')
    ->transition('branch-b', 'merge')
    ->transition('merge', Workflow::END);
```

`branch-a` and `branch-b` run as independent queue jobs. Fan-in barrier logic (waiting for all branches before proceeding) can be handled with the built-in `ReduceNode` or in your own node by inspecting state.

### Dynamic Fan-out with Send

To fan out over a dynamic list — where the number of parallel branches isn't known until runtime — return `Send` objects from a branch edge resolver:

```php
use Cainy\Laragraph\Routing\Send;

->branch('planner', function(array $state): array {
    return array_map(
        fn(string $query) => new Send('worker', ['query' => $query]),
        $state['queries']
    );
}, targets: ['worker'])
```

Each `Send` dispatches an independent `ExecuteNode` job. The target node receives the payload via `$context->isolatedPayload`.

The same thing is available as the `SendNode` prebuilt (see [Built-in Nodes](#built-in-nodes)) or via the `send()` expression function:

```php
// Branch edge expression string
->branch('planner', "send('worker', state['queries'], 'query')", targets: ['worker'])
```

---

## Running a Workflow

### Registering Workflows

Register workflows in your `AppServiceProvider` or a dedicated provider:

```php
use Cainy\Laragraph\Facades\Laragraph;

public function boot(): void
{
    Laragraph::register('my-pipeline', fn() => MyPipelineWorkflow::build());
}
```

Or register them via the config file:

```php
// config/laragraph.php
'workflows' => [
    'my-pipeline' => MyPipelineWorkflow::class,
],
```

### Starting a Run

```php
use Cainy\Laragraph\Facades\Laragraph;

$run = Laragraph::start('my-pipeline', initialState: [
    'input' => 'Hello, world!',
]);

echo $run->id;     // WorkflowRun ID
echo $run->status; // RunStatus::Running
```

The run is created synchronously. Node jobs are dispatched to your queue immediately after.

### Starting from a Blueprint

For ad-hoc workflows that aren't pre-registered, pass a `Workflow` builder directly. The graph is serialized as a snapshot so workers can reconstruct it without a registry:

```php
$run = Laragraph::startFromBlueprint(
    blueprint: Workflow::create()
        ->withName('ad-hoc-pipeline')
        ->addNode('step', MyNode::class)
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END),
    initialState: ['input' => 'data'],
);
```

> Snapshot workflows require all edges to use expression strings (not Closures), since Closures cannot be serialized.

### Controlling a Run

```php
// Pause a running workflow
Laragraph::pause($run->id);

// Resume a paused workflow, optionally merging additional state
Laragraph::resume($run->id, ['approved' => true]);

// Abort a workflow (sets status to Failed, clears all pointers)
Laragraph::abort($run->id);
```

---

## State

State is a plain PHP array that persists in the `workflow_runs.state` column. Every node receives the full current state and returns a **mutation** — a partial array of keys to update.

The **reducer** determines how mutations are merged into the existing state.

### Reducers

LaraGraph ships with three reducers:

| Class | Behaviour |
|---|---|
| `SmartReducer` *(default)* | List arrays are **appended**. Scalars and associative arrays are **overwritten**. |
| `MergeReducer` | Deep recursive merge for all keys. |
| `OverwriteReducer` | Shallow `array_merge` — always overwrites. |

`SmartReducer` is the right default for most agent workflows: message histories accumulate naturally, while scalar values like `status` or `score` simply overwrite.

### Custom Reducer

Implement `StateReducerInterface` and bind it in your service provider, or attach it to a specific workflow:

```php
// Globally
$this->app->bind(StateReducerInterface::class, MyReducer::class);

// Per workflow
Workflow::create()
    ->withReducer(MyReducer::class)
    // ...
```

---

## Human-in-the-Loop

LaraGraph has first-class support for pausing workflows and waiting for human input.

### interrupt_before

Pause the run **before** a node executes. On resume, the node runs normally.

```php
Workflow::create()
    ->addNode('review', ReviewNode::class)
    ->interruptBefore('review');
```

### interrupt_after

Pause the run **after** a node executes but before its outgoing edges are evaluated. Use this when you want a human to inspect output before the workflow continues.

```php
Workflow::create()
    ->addNode('drafter', DrafterNode::class)
    ->addNode('publish',  PublishNode::class)
    ->transition(Workflow::START, 'drafter')
    ->transition('drafter', 'publish')
    ->transition('publish', Workflow::END)
    ->interruptAfter('drafter');
```

### Resuming

Call `Laragraph::resume()` with any additional state to merge before the run continues:

```php
Laragraph::resume($run->id, [
    'meta' => ['approved' => true],
]);
```

### Dynamic Pause from a Node

Any node can pause the run at runtime by throwing `NodePausedException`. Unlike `interruptBefore/After`, this lets the node itself decide whether to pause based on runtime state:

```php
use Cainy\Laragraph\Exceptions\NodePausedException;

class ConfidenceCheckNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        if ($state['confidence'] < 0.7) {
            throw new NodePausedException($context->nodeName);
        }

        return ['status' => 'confident'];
    }
}
```

The engine keeps the node's active pointer alive so `resume()` re-dispatches it from the same position.

You can also pass state mutations to persist before pausing (useful to record why the pause happened):

```php
throw new NodePausedException(
    nodeName: $context->nodeName,
    stateMutation: ['gate_reason' => 'Score too low'],
);
```

---

## Node Contracts

Nodes can implement optional contracts to declare capabilities to the engine.

### HasName

Give a node a stable identifier used in edge routing and graph visualization:

```php
use Cainy\Laragraph\Contracts\HasName;

class ResearchAgentNode implements Node, HasName
{
    public function name(): string
    {
        return 'research-agent';
    }
}
```

### HasTags

Emit metadata alongside the `NodeCompleted` event — useful for tracking token usage, model names, cost centers, or tenant IDs:

```php
use Cainy\Laragraph\Contracts\HasTags;

class LLMNode implements Node, HasTags
{
    public function tags(): array
    {
        return [
            'model'       => 'claude-sonnet-4-6',
            'cost_center' => 'marketing',
        ];
    }
}
```

### HasRetryPolicy

Define per-node retry behaviour with exponential backoff and optional jitter:

```php
use Cainy\Laragraph\Contracts\HasRetryPolicy;
use Cainy\Laragraph\Engine\RetryPolicy;

class FlakyAPINode implements Node, HasRetryPolicy
{
    public function retryPolicy(): RetryPolicy
    {
        return new RetryPolicy(
            initialInterval: 1.0,   // seconds before first retry
            backoffFactor:   2.0,   // doubles each attempt
            maxInterval:     30.0,  // cap at 30 seconds
            maxAttempts:     5,
            jitter:          true,  // add ±25% randomness
        );
    }
}
```

Restrict retries to specific exception types:

```php
new RetryPolicy(
    maxAttempts: 3,
    retryOn: [RateLimitException::class, TimeoutException::class],
)

// Or with a Closure for full control:
new RetryPolicy(
    maxAttempts: 3,
    retryOn: fn(Throwable $e) => $e->getCode() === 429,
)
```

The current attempt is available via `$context->attempt` and `$context->maxAttempts`.

### HasLoop

Declare that a node should loop — driving tool execution cycles, polling, or any other repeated sub-task. The compiler automatically injects the loop edges at compile time.

```php
use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;

class PollingNode implements Node, HasLoop
{
    public function loopNode(string $nodeName): Node
    {
        return new CheckStatusNode();
    }

    public function loopCondition(): string|\Closure
    {
        return "state['status'] !== 'done'";
    }
}
```

When compiled, the engine injects a `{name}.__loop__` node and guards existing exit edges with the negated condition. Use `Workflow::toolNode('name')` to reference the synthetic loop node in interrupt points:

```php
->interruptBefore(Workflow::toolNode('agent'))
```

### SerializableNode

Implement this on any node that needs to survive serialization in a snapshot workflow. The node must be able to round-trip through `toArray()` / `fromArray()`:

```php
use Cainy\Laragraph\Contracts\SerializableNode;

final class MyNode implements SerializableNode
{
    public function __construct(public readonly string $prompt) {}

    public function handle(NodeExecutionContext $context, array $state): array { /* ... */ }

    public function toArray(): array
    {
        return ['__synthetic' => 'my_node', 'prompt' => $this->prompt];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['prompt']);
    }
}
```

Register the type so the deserializer can find it:

```php
Workflow::registerSyntheticType('my_node', MyNode::class);
```

---

## Built-in Nodes

All built-in nodes implement `SerializableNode` and can be used in both registered and snapshot workflows.

### GateNode

Pauses the workflow unconditionally until manually resumed. Use this as a static approval gate inside a workflow graph.

```php
use Cainy\Laragraph\Nodes\GateNode;

Workflow::create()
    ->addNode('approve', new GateNode(reason: 'Manager approval required'))
    ->transition('draft', 'approve')
    ->transition('approve', 'publish');
```

When the gate triggers, `state['gate_reason']` is set to the reason string. Resume the run via `Laragraph::resume($runId)` once approval is given.

### SendNode

Fan-out node — dispatches a `Send` for each item in a state list, sending each to the same target node with an isolated payload.

```php
use Cainy\Laragraph\Nodes\SendNode;

Workflow::create()
    ->addNode('fanout', new SendNode(
        sourceKey:  'queries',  // state key containing the list
        targetNode: 'worker',   // node to dispatch each item to
        payloadKey: 'query',    // key name inside the isolated payload
    ))
    ->addNode('worker', WorkerNode::class)
    ->transition(Workflow::START, 'fanout')
    ->transition('fanout', 'worker');
```

Inside `WorkerNode`, each instance receives its slice via `$context->isolatedPayload['query']`.

### ReduceNode

Fan-in barrier — pauses until a required number of items have accumulated in a state key. Use after a `SendNode` to wait for all parallel workers to report back.

```php
use Cainy\Laragraph\Nodes\ReduceNode;

// Static expected count
->addNode('barrier', new ReduceNode(collectKey: 'results', expectedCount: 3))

// Dynamic count read from state
->addNode('barrier', new ReduceNode(collectKey: 'results', countFromKey: 'query_count'))
```

When the required count isn't met, the node re-pauses itself. Resume is triggered by the next worker completing.

### HttpNode

Makes an HTTP request and stores the response in state. The URL supports `{state.key}` interpolation.

```php
use Cainy\Laragraph\Nodes\HttpNode;

->addNode('fetch', new HttpNode(
    url:         'https://api.example.com/items/{state.item_id}',
    method:      'GET',
    headers:     ['Authorization' => 'Bearer token'],
    responseKey: 'api_response',  // defaults to 'response'
))
```

The response is stored as `['status' => 200, 'body' => [...], 'ok' => true]` under `responseKey`.

For POST/PUT/PATCH requests, set `bodyKey` to a state key whose value will be sent as the request body:

```php
new HttpNode(url: '...', method: 'POST', bodyKey: 'payload', responseKey: 'result')
```

### DelayNode

Pauses execution for a given number of seconds, then continues.

```php
use Cainy\Laragraph\Nodes\DelayNode;

->addNode('wait', new DelayNode(seconds: 300))  // pause for 5 minutes
```

On first execution the node stores a resume-after timestamp and pauses. Your application must call `Laragraph::resume($runId)` after the delay (e.g. via a scheduled command or a queued job dispatched with a delay).

### CacheNode

Reads from or writes to the Laravel cache. The cache key supports `{state.key}` interpolation.

```php
use Cainy\Laragraph\Nodes\CacheNode;

// Read from cache into state
->addNode('load',  new CacheNode(operation: 'get',    cacheKey: 'report:{state.user_id}', stateKey: 'cached_report'))

// Write state value into cache
->addNode('store', new CacheNode(operation: 'put',    cacheKey: 'report:{state.user_id}', stateKey: 'report', ttl: 3600))

// Invalidate a cache entry
->addNode('bust',  new CacheNode(operation: 'forget', cacheKey: 'report:{state.user_id}', stateKey: 'report'))
```

### NotifyNode

Dispatches a Laravel event with values from state as constructor arguments.

```php
use Cainy\Laragraph\Nodes\NotifyNode;

->addNode('notify', new NotifyNode(
    eventClass: ReportReady::class,
    dataKeys:   ['user_id', 'report_url'],  // passed as positional args to the event constructor
))
```

---

## Prism Integration

LaraGraph ships with first-class support for [Prism](https://github.com/prism-php/prism) via the `Cainy\Laragraph\Integrations\Prism` namespace.

```bash
composer require prism-php/prism
```

### PrismNode

A concrete, configurable LLM node. No subclass needed for common use cases:

```php
use Cainy\Laragraph\Integrations\Prism\PrismNode;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$workflow = Workflow::create()
    ->addNode('agent', new PrismNode(
        provider:     Provider::Anthropic,
        model:        'claude-sonnet-4-6',
        systemPrompt: 'You are a helpful assistant.',
        maxTokens:    1024,
        tools: [
            (new Tool)
                ->as('get_weather')
                ->for('Get weather for a city')
                ->withStringParameter('city', 'City name')
                ->using(fn(string $city): string => "Sunny, 22°C in {$city}"),
        ],
    ))
    ->transition(Workflow::START, 'agent')
    ->transition('agent', Workflow::END)
    ->compile();
```

`PrismNode` serializes Prism `Message` objects to/from plain arrays for state storage. It returns the assistant's response (including tool calls) as a single message appended to `state['messages']`.

Override `getPrompt()` or `tools()` for dynamic behaviour:

```php
class ResearchAgent extends PrismNode
{
    protected function getPrompt(array $state): string
    {
        return 'Research: ' . $state['topic'];
    }

    public function tools(): array
    {
        return [/* dynamic tools */];
    }
}
```

### ToolNode

Abstract base for nodes that manually execute tool calls from `state['messages']`. Implement `toolMap()` to return a map of tool names to callables:

```php
use Cainy\Laragraph\Integrations\Prism\ToolNode;

class WeatherToolNode extends ToolNode
{
    protected function toolMap(): array
    {
        return [
            'get_weather' => fn(array $args): string =>
                "Sunny, 22°C in " . ($args['city'] ?? 'unknown'),
        ];
    }
}
```

Tool results are appended to `state['messages']` in Prism's `tool_result` format.

> You typically don't need `ToolNode` when using automatic tool loops. It exists as an escape hatch for custom tool routing.

### Automatic Tool Loops

`PrismNode` implements `HasLoop`. When a node has tools, calling `->compile()` automatically injects a tool execution loop — no manual wiring required.

```php
$workflow = Workflow::create()
    ->addNode('agent', new PrismNode(tools: [$weatherTool, $searchTool]))
    ->transition(Workflow::START, 'agent')
    ->transition('agent', Workflow::END)
    ->compile();

// Compiled graph:
// START → agent ──(tool calls present)──→ agent.__loop__ → agent
//                ──(no tool calls)──────→ END
```

The compiler:

1. Detects nodes implementing `HasLoop`
2. Injects a `{name}.__loop__` node (a `ToolExecutor` for `PrismNode`)
3. Guards existing outgoing edges with the negated loop condition
4. Adds the loop entry and loop-back edges

To interrupt before tool execution runs:

```php
->interruptBefore(Workflow::toolNode('agent'))
```

### Manual Tool Routing

For full control over tool routing, skip `HasLoop` and wire edges explicitly:

```php
$workflow = Workflow::create()
    ->addNode('agent', MyAgentNode::class)
    ->addNode('tools', WeatherToolNode::class)
    ->transition(Workflow::START, 'agent')
    ->transition('agent', 'tools', "not_empty(last(state['messages'])['tool_calls'] ?? [])")
    ->transition('agent', Workflow::END, "empty(last(state['messages'])['tool_calls'] ?? [])")
    ->transition('tools', 'agent');
```

---

## Laravel AI Integration

LaraGraph integrates with [Laravel AI](https://github.com/laravel/ai) via the `AsGraphNode` trait. Any Laravel AI agent can be dropped into a workflow graph without adapter classes.

```bash
composer require laravel/ai
```

### AsGraphNode Trait

Add `AsGraphNode` to a standard Laravel AI agent to make it a Laragraph node:

```php
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Integrations\LaravelAi\AsGraphNode;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ResearchAgent implements Agent, Node
{
    use AsGraphNode, Promptable;

    public function instructions(): string
    {
        return 'You are a research assistant.';
    }

    protected function getAgentPrompt(): string
    {
        return 'Research: ' . ($this->state['topic'] ?? 'general');
    }
}
```

The trait hydrates `$this->state` and `$this->ctx` before execution, calls Laravel AI's native `prompt()`, and converts the response into a state mutation automatically.

Register it like any other node:

```php
Workflow::create()
    ->addNode('researcher', ResearchAgent::class)
    ->transition(Workflow::START, 'researcher')
    ->transition('researcher', Workflow::END);
```

### Structured Output

If your agent implements `HasStructuredOutput`, the trait maps the structured response keys directly to state mutation keys:

```php
use Laravel\Ai\Contracts\HasStructuredOutput;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ClassifierAgent implements Agent, Node, HasStructuredOutput
{
    use AsGraphNode, Promptable;

    public function instructions(): string
    {
        return 'Classify the input into a category and confidence score.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category'   => $schema->string()->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}
```

After execution, `state['category']` and `state['confidence']` are set directly. Override `mutateStateWithStructuredOutput()` to remap keys if needed.

### Tool-Using Agents

Laravel AI agents implementing `HasTools` are automatically detected by the compiler. Tool loop injection works exactly as with `PrismNode`:

```php
use Laravel\Ai\Contracts\HasTools;

class WeatherAgent implements Agent, Node, HasTools
{
    use AsGraphNode, Promptable;

    public function tools(): array
    {
        return [new GetWeather];
    }
}

Workflow::create()
    ->addNode('weather', WeatherAgent::class)
    ->transition(Workflow::START, 'weather')
    ->transition('weather', Workflow::END)
    ->compile(); // auto-injects weather.__loop__
```

---

## Sub-graph Workflows

Any `CompiledWorkflow` implements `Node` and can be embedded inside another workflow. This lets you compose complex pipelines from smaller, independently testable pieces.

```php
$researchSubgraph = Workflow::create()
    ->withName('research')
    ->addNode('search',  SearchNode::class)
    ->addNode('extract', ExtractNode::class)
    ->transition(Workflow::START, 'search')
    ->transition('search',  'extract')
    ->transition('extract', Workflow::END)
    ->compile();

$parentWorkflow = Workflow::create()
    ->addNode('research', $researchSubgraph)
    ->addNode('write',    WriteNode::class)
    ->transition(Workflow::START, 'research')
    ->transition('research', 'write')
    ->transition('write', Workflow::END);
```

When the engine executes a `CompiledWorkflow` node:

1. A child `WorkflowRun` is created and linked via `parent_run_id` / `parent_node_name`.
2. The child workflow starts normally — its nodes run as independent queue jobs.
3. The parent run **pauses** at the sub-graph node, keeping its pointer alive.
4. When the child completes, the engine resumes the parent automatically.
5. The parent node computes the state delta from the child's final state and returns it as a mutation.

```php
$run->parent;    // ?WorkflowRun
$run->children;  // Collection<WorkflowRun>
```

---

## Recursion Limit

The engine tracks total node executions per run and throws `RecursionLimitExceeded` if the limit is hit. This prevents runaway loops from consuming resources indefinitely.

The default limit is `config('laragraph.recursion_limit', 25)`. Override it per workflow:

```php
Workflow::create()
    ->withRecursionLimit(100)
    // ...
```

---

## Serializable Workflows

Workflows started via `Laragraph::startFromBlueprint()` are serialized as a JSON snapshot in the `workflow_runs.snapshot` column so queue workers can reconstruct the graph without a registry lookup.

**Constraints for snapshot workflows:**

- All edge conditions must be expression strings, not Closures.
- All node instances must implement `SerializableNode`.
- Class-string nodes (resolved via the container) are always safe.

Register custom serializable node types:

```php
Workflow::registerSyntheticType('my_node', MyNode::class);
```

Built-in synthetic types: `gate`, `send`, `reduce`, `http`, `delay`, `cache`, `notify`, `tool_executor`.

---

## Events

LaraGraph fires events throughout the workflow lifecycle. All events implement `ShouldBroadcast` and are broadcast on the workflow channel when broadcasting is enabled.

| Event | Payload |
|---|---|
| `WorkflowStarted` | `runId`, `workflowKey` |
| `NodeExecuting` | `runId`, `nodeName` |
| `NodeCompleted` | `runId`, `nodeName`, `mutation`, `tags` |
| `NodeFailed` | `runId`, `nodeName`, `exception` |
| `WorkflowCompleted` | `runId` |
| `WorkflowFailed` | `runId`, `exception` |
| `WorkflowResumed` | `runId` |

### Broadcasting

Enable broadcasting in your `.env`:

```env
LARAGRAPH_BROADCASTING_ENABLED=true
LARAGRAPH_CHANNEL_TYPE=private       # public | private | presence
LARAGRAPH_CHANNEL_PREFIX=workflow.
```

Each run broadcasts on channel `{prefix}{runId}` (e.g. `workflow.42`). Authorize the channel in `routes/channels.php` as needed.

---

## Configuration

```php
// config/laragraph.php
return [
    // Queue name for ExecuteNode jobs
    'queue' => env('LARAGRAPH_QUEUE', 'default'),

    // Queue connection (null = default connection)
    'connection' => env('LARAGRAPH_QUEUE_CONNECTION'),

    // Default max attempts per node (overridden per-node via HasRetryPolicy)
    'max_node_attempts' => 3,

    // Default node timeout in seconds
    'node_timeout' => 60,

    // Maximum node executions per run before RecursionLimitExceeded is thrown
    'recursion_limit' => 25,

    // Soft-delete workflow runs older than this many days
    'prunable_after_days' => 30,

    // Pre-registered workflows (name => class or callable)
    'workflows' => [],

    // Default retry backoff settings (overridden per-node via HasRetryPolicy)
    'retry' => [
        'initial_interval' => 0.5,
        'backoff_factor'   => 2.0,
        'max_interval'     => 128.0,
        'jitter'           => true,
    ],

    'broadcasting' => [
        'enabled'        => env('LARAGRAPH_BROADCASTING_ENABLED', false),
        'channel_type'   => env('LARAGRAPH_CHANNEL_TYPE', 'private'),
        'channel_prefix' => env('LARAGRAPH_CHANNEL_PREFIX', 'workflow.'),
    ],
];
```

---

## Testing

```bash
composer test
```

LaraGraph works with the `sync` queue driver in tests — set `QUEUE_CONNECTION=sync` in your `phpunit.xml` and runs execute synchronously, making assertions straightforward:

```php
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Enums\RunStatus;

it('completes the pipeline', function () {
    $run = Laragraph::start('my-pipeline', ['input' => 'hello']);

    expect($run->fresh())
        ->status->toBe(RunStatus::Completed)
        ->state->toHaveKey('output');
});
```

For unit-testing individual nodes, use the `makeContext()` test helper:

```php
use function Cainy\Laragraph\Tests\makeContext;

it('returns a summary mutation', function () {
    $node = new SummarizeNode();

    $mutation = $node->handle(
        makeContext(nodeName: 'summarize'),
        ['text' => 'Long article...'],
    );

    expect($mutation)->toHaveKey('summary');
});
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
