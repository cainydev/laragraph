<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\HasName;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

class Workflow implements HasName, Node
{
    public const string START = '__START__';

    public const string END = '__END__';

    /** @var array<string, string|Node> */
    private array $nodes = [];

    /** @var list<Edge|BranchEdge> */
    private array $edges = [];

    private ?string $reducerClass = null;

    private ?int $recursionLimit = null;

    /** @var string[] */
    private array $interruptBefore = [];

    /** @var string[] */
    private array $interruptAfter = [];

    private ?bool $cascadeFailureOverride = null;

    public function name(): string
    {
        return static::class;
    }

    /**
     * When used as a node inside a parent workflow, this method acts as a dispatcher:
     * - First execution: spawns a child WorkflowRun linked to the parent, then pauses.
     * - On resume (after child completes): reads the child's final state and returns the delta.
     */
    public function handle(NodeExecutionContext $context, array $state): array
    {
        // Resume path: a completed child run id is carried on the resumed
        // payload (Laragraph::resumeFromChild). This works even for concurrent
        // per-item Sends sharing the same sub-workflow node.
        $resumeChildRunId = $context->payload('__resume_child_run_id');

        // Fallback: gate-style pause / non-Send embedding uses routing slot.
        $routedChildRunId = $context->routing['child_runs'][$context->nodeName] ?? null;

        $childRunId = $resumeChildRunId ?? $routedChildRunId;

        if ($childRunId === null) {
            // When dispatched via Send (per-item pipeline), the child seed is the
            // isolated payload — not parent state. This implements the canonical
            // "fan-out → per-item sub-workflow → barrier" shape without leaking
            // parent state into the child.
            $isSendDispatch = $context->isSendExecution();
            $initialState = $isSendDispatch
                ? ($context->isolatedPayload ?? [])
                : $state;

            // Dispatching a child may run it synchronously (sync queue). Swallow
            // sync-failure propagation here — the child has already recorded its
            // own Failed state via its failed() hook, and the cascade listener
            // will handle propagating to the parent (if enabled).
            try {
                $childRun = app(Laragraph::class)->startChildWorkflow(
                    workflow: $this,
                    initialState: $initialState,
                    parentRunId: $context->runId,
                    parentNodeName: $context->nodeName,
                );
            } catch (\Throwable $e) {
                $childRun = WorkflowRun::where('parent_run_id', $context->runId)
                    ->where('parent_node_name', $context->nodeName)
                    ->latest('id')
                    ->first();

                if ($childRun === null) {
                    throw $e;
                }
            }

            // For Send dispatches we don't persist the child id into the
            // parent's routing slot (concurrent Sends would collide on the
            // same key). We still surface it on the exception so the engine's
            // sync-queue early-resume path can re-dispatch the correct child.
            throw new NodePausedException(
                nodeName: $context->nodeName,
                childRunId: $childRun->id,
                childRunIdIsPersistable: ! $isSendDispatch,
            );
        }

        $childRun = WorkflowRun::findOrFail($childRunId);

        // For Send-dispatched sub-workflows the child seed was the payload, not
        // parent state — so the delta is the child's full final state (any key
        // the child produced is new).
        if ($resumeChildRunId !== null) {
            return $childRun->state;
        }

        return $this->recursiveDiff($childRun->state, $state);
    }

    /**
     * Recursively compute the difference between two arrays.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function recursiveDiff(array $new, array $old): array
    {
        $diff = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old)) {
                $diff[$key] = $value;
            } elseif (is_array($value) && is_array($old[$key])) {
                $nested = $this->recursiveDiff($value, $old[$key]);
                if (! empty($nested)) {
                    $diff[$key] = $value;
                }
            } elseif ($value !== $old[$key]) {
                $diff[$key] = $value;
            }
        }

        return $diff;
    }

    /**
     * Define the workflow's nodes and edges.
     * Override this method in subclasses instead of calling compile() directly.
     */
    public function definition(): void {}

    /**
     * Called after the WorkflowRun is persisted and before the first nodes are dispatched.
     */
    public function onStarting(WorkflowRun $run): void {}

    /**
     * Called after all nodes have completed and the run status is set to Completed.
     */
    public function onCompleted(WorkflowRun $run): void {}

    /**
     * Called after a node failure has exhausted all retries and the run status is set to Failed.
     */
    public function onFailed(WorkflowRun $run, \Throwable $exception): void {}

    public static function toolNode(string $nodeName): string
    {
        return $nodeName.'.__loop__';
    }

    public function addNode(string $name, string|Node $node): static
    {
        $this->nodes[$name] = $node;

        return $this;
    }

    public function transition(string $from, string $to, ?\Closure $when = null): static
    {
        $this->edges[] = new Edge($from, $to, $when);

        return $this;
    }

    /**
     * @param  string[]  $targets  Possible destination node names for visualization (optional but recommended).
     */
    public function branch(string $from, \Closure $resolver, array $targets = []): static
    {
        $this->edges[] = new BranchEdge($from, $resolver, $targets);

        return $this;
    }

    public function withReducer(string $reducerClass): static
    {
        $this->reducerClass = $reducerClass;

        return $this;
    }

    /**
     * Set the maximum number of node executions before the workflow is marked as failed.
     * Defaults to config('laragraph.recursion_limit', 25).
     */
    public function withRecursionLimit(int $limit): static
    {
        $this->recursionLimit = $limit;

        return $this;
    }

    /**
     * Pause execution BEFORE the given node(s) run. On resume the node executes.
     */
    public function interruptBefore(string ...$nodeNames): static
    {
        $this->interruptBefore = array_merge($this->interruptBefore, $nodeNames);

        return $this;
    }

    /**
     * Pause execution AFTER the given node(s) complete. On resume edges evaluate.
     */
    public function interruptAfter(string ...$nodeNames): static
    {
        $this->interruptAfter = array_merge($this->interruptAfter, $nodeNames);

        return $this;
    }

    /**
     * Fluent builder form — sets the cascade behaviour without requiring a subclass.
     * Only meaningful when this Workflow is embedded as a node in another.
     */
    public function cascadeFailure(bool $cascade = true): static
    {
        $this->cascadeFailureOverride = $cascade;

        return $this;
    }

    /**
     * Whether a failure in this child workflow should propagate to the parent
     * that embedded it. Defaults to true — override in subclasses for
     * map-reduce patterns that aggregate partial child failures.
     */
    public function shouldCascadeFailure(): bool
    {
        return $this->cascadeFailureOverride ?? true;
    }

    public function compile(): CompiledWorkflow
    {
        $this->nodes = [];
        $this->edges = [];
        $this->interruptBefore = [];
        $this->interruptAfter = [];

        $this->definition();
        $this->validate();

        $nodes = $this->nodes;
        $edges = $this->edges;

        $this->injectLoops($nodes, $edges);

        return new CompiledWorkflow(
            nodes: $nodes,
            edges: $edges,
            reducerClass: $this->reducerClass,
            interruptBefore: $this->interruptBefore,
            interruptAfter: $this->interruptAfter,
            recursionLimit: $this->recursionLimit,
        );
    }

    /**
     * @param  array<string, string|Node>  $nodes
     * @param  list<Edge|BranchEdge>  $edges
     */
    private function injectLoops(array &$nodes, array &$edges): void
    {
        foreach ($nodes as $name => $node) {
            if (! ($node instanceof HasLoop)) {
                continue;
            }

            $loopNodeName = $name.'.__loop__';
            $nodes[$loopNodeName] = $node->loopNode($name);
            $condition = $node->loopCondition();

            // Guard existing edges FROM this node with the negated loop condition
            $edges = array_map(function (Edge|BranchEdge $edge) use ($name, $condition): Edge|BranchEdge {
                if ($edge->from !== $name) {
                    return $edge;
                }

                if ($edge instanceof BranchEdge) {
                    return $this->guardBranchEdge($edge, $condition);
                }

                return $this->guardEdge($edge, $condition);
            }, $edges);

            // Loop entry edge: fire when condition is true
            $edges[] = new Edge($name, $loopNodeName, $condition);
            // Loop back edge: always return to parent node after loop node runs
            $edges[] = new Edge($loopNodeName, $name);
        }
    }

    private function guardEdge(Edge $edge, \Closure $condition): Edge
    {
        $original = $edge->when;

        return new Edge($edge->from, $edge->to, function (array $state) use ($condition, $original): bool {
            if ($condition($state)) {
                return false;
            }

            if ($original === null) {
                return true;
            }

            return (bool) $original($state);
        });
    }

    private function guardBranchEdge(BranchEdge $edge, \Closure $condition): BranchEdge
    {
        $original = $edge->resolver;

        return new BranchEdge($edge->from, function (array $state) use ($condition, $original): array {
            if ($condition($state)) {
                return [];
            }

            $result = $original($state);

            return is_array($result) ? $result : [(string) $result];
        }, $edge->targets);
    }

    private function validate(): void
    {
        $nodeNames = array_keys($this->nodes);
        $pseudoNodes = [self::START, self::END];
        $allNodes = array_merge($nodeNames, $pseudoNodes);

        $hasStartEdge = false;

        foreach ($this->edges as $edge) {
            if ($edge->from === self::START) {
                $hasStartEdge = true;
            }

            if ($edge->from === self::END) {
                throw new \InvalidArgumentException('Edges from __END__ are not allowed.');
            }

            if ($edge instanceof BranchEdge) {
                if (! in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("BranchEdge references unknown 'from' node [{$edge->from}].");
                }
            } else {
                if ($edge->to === self::START) {
                    throw new \InvalidArgumentException('Edges to __START__ are not allowed.');
                }
                if (! in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'from' node [{$edge->from}].");
                }
                if (! in_array($edge->to, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'to' node [{$edge->to}].");
                }
            }
        }

        if (! $hasStartEdge) {
            throw new \InvalidArgumentException('Workflow must have at least one edge from __START__.');
        }
    }
}
