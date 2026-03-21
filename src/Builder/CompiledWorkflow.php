<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\NodeInterface;
use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;

class CompiledWorkflow
{
    /** @var array<string, list<Edge|BranchEdge>> */
    private array $edgeIndex = [];

    /**
     * @param array<string, string|NodeInterface> $nodes
     * @param list<Edge|BranchEdge>               $edges
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $edges,
        private readonly ?string $reducerClass = null,
    ) {
        foreach ($edges as $edge) {
            $this->edgeIndex[$edge->from][] = $edge;
        }
    }

    public function resolveNode(string $name): NodeInterface
    {
        $node = $this->nodes[$name]
            ?? throw new \InvalidArgumentException("Node [{$name}] is not defined in the workflow.");

        if ($node instanceof NodeInterface) {
            return $node;
        }

        return app($node);
    }

    /**
     * @return string[]
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
     * @return string[]
     */
    public function getStartNodes(): array
    {
        return $this->resolveNextNodes(Workflow::START, []);
    }

    public function getReducer(): StateReducerInterface
    {
        if ($this->reducerClass !== null) {
            return app($this->reducerClass);
        }

        return app(StateReducerInterface::class);
    }

    /**
     * @return array<string, string|NodeInterface>
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
