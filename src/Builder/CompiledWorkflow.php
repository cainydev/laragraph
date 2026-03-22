<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\HasName;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Routing\Send;

class CompiledWorkflow implements HasName, Node
{
    /** @var array<string, list<Edge|BranchEdge>> */
    private array $edgeIndex = [];

    /**
     * @param  array<string, string|Node>  $nodes
     * @param  list<Edge|BranchEdge>  $edges
     * @param  string[]  $interruptBefore
     * @param  string[]  $interruptAfter
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $edges,
        private readonly ?string $reducerClass = null,
        private readonly array $interruptBefore = [],
        private readonly array $interruptAfter = [],
        private readonly ?string $workflowName = null,
    ) {
        foreach ($edges as $edge) {
            $this->edgeIndex[$edge->from][] = $edge;
        }
    }

    // -------------------------------------------------------------------------
    // Node + HasName implementation
    // -------------------------------------------------------------------------

    public function name(): string
    {
        return $this->workflowName ?? static::class;
    }

    /**
     * When used as a node inside a parent workflow, this method acts as a dispatcher:
     * - First execution: spawns a child WorkflowRun linked to the parent, then pauses.
     * - On resume (after child completes): reads the child's final state and returns the delta.
     */
    public function handle(NodeExecutionContext $context, array $state): array
    {
        $childRunKey = "__child_run_{$context->nodeName}";
        $childRunId = $state[$childRunKey] ?? null;

        if ($childRunId === null) {
            // Spawn the child and pause the parent.
            $childRun = app(Laragraph::class)->startChildWorkflow(
                compiled: $this,
                initialState: $state,
                parentRunId: $context->runId,
                parentNodeName: $context->nodeName,
                key: $context->workflowKey.'.'.$context->nodeName,
            );

            throw new NodePausedException(
                nodeName: $context->nodeName,
                stateMutation: [$childRunKey => $childRun->id],
            );
        }

        // Resuming after child completed — diff child's final state against what we sent in.
        $childRun = WorkflowRun::findOrFail($childRunId);
        $delta = array_diff_assoc($childRun->state, $state);
        $delta[$childRunKey] = null; // Remove the child reference marker

        return $delta;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize to the same array structure that Workflow::fromJson() expects,
     * so the snapshot column can reconstruct this workflow.
     */
    public function toArray(): array
    {
        $serializedNodes = array_map(
            fn ($node) => is_string($node) ? $node : $node::class,
            $this->nodes,
        );

        $serializedEdges = array_map(fn ($edge) => $edge->toArray(), $this->edges);

        return [
            'nodes' => $serializedNodes,
            'edges' => $serializedEdges,
            'reducerClass' => $this->reducerClass,
            'interruptBefore' => $this->interruptBefore,
            'interruptAfter' => $this->interruptAfter,
        ];
    }

    // -------------------------------------------------------------------------
    // Engine interface
    // -------------------------------------------------------------------------

    public function resolveNode(string $name): Node
    {
        $node = $this->nodes[$name]
            ?? throw new \InvalidArgumentException("Node [{$name}] is not defined in the workflow.");

        if ($node instanceof Node) {
            return $node;
        }

        return app($node);
    }

    /**
     * @return array<string|Send>
     */
    public function resolveNextNodes(string $fromNode, array $state): array
    {
        $edges = $this->edgeIndex[$fromNode] ?? [];
        $targets = [];

        foreach ($edges as $edge) {
            if ($edge instanceof BranchEdge) {
                foreach ($edge->resolve($state) as $target) {
                    $targets[] = $target;
                }
            } elseif ($edge->evaluate($state)) {
                $targets[] = $edge->to;
            }
        }

        return $targets;
    }

    /**
     * @return array<string|Send>
     */
    public function getStartNodes(array $state = []): array
    {
        return $this->resolveNextNodes(Workflow::START, $state);
    }

    public function getReducer(): StateReducerInterface
    {
        if ($this->reducerClass !== null) {
            return app($this->reducerClass);
        }

        return app(StateReducerInterface::class);
    }

    public function shouldInterruptBefore(string $nodeName): bool
    {
        return in_array($nodeName, $this->interruptBefore, true);
    }

    public function shouldInterruptAfter(string $nodeName): bool
    {
        return in_array($nodeName, $this->interruptAfter, true);
    }

    /**
     * @return array<string, string|Node>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<Edge|BranchEdge>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }
}
