<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Routing\Send;

class CompiledWorkflow
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
        private readonly ?int $recursionLimit = null,
    ) {
        foreach ($edges as $edge) {
            $this->edgeIndex[$edge->from][] = $edge;
        }
    }

    public function resolveNode(string $name): Node
    {
        $node = $this->nodes[$name]
            ?? throw new \InvalidArgumentException("Node [{$name}] is not defined in the workflow.");

        if ($node instanceof Node) {
            return $node;
        }

        $resolved = app($node);

        if (! $resolved instanceof Node) {
            throw new \InvalidArgumentException("Class [{$node}] was registered as a node but does not implement ".Node::class.'.');
        }

        return $resolved;
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

    public function getRecursionLimit(): int
    {
        return $this->recursionLimit ?? (int) config('laragraph.recursion_limit', 25);
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
     * Return all node names that have a direct edge pointing to $nodeName.
     * Used by the engine's IsFanInBarrier pre-check to identify which predecessor
     * nodes must have fully completed before the barrier is allowed to fire.
     *
     * @return string[]
     */
    public function getIncomingNodesFor(string $nodeName): array
    {
        $incoming = [];

        foreach ($this->edges as $edge) {
            if ($edge instanceof BranchEdge) {
                if (in_array($nodeName, $edge->targets, true)) {
                    $incoming[] = $edge->from;
                }
            } elseif ($edge->to === $nodeName) {
                $incoming[] = $edge->from;
            }
        }

        return array_values(array_unique($incoming));
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
