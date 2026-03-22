<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use JsonException;

class Workflow
{
    public const string START = '__START__';
    public const string END = '__END__';

    /** @var array<string, string|Node> */
    private array $nodes = [];

    /** @var list<Edge|BranchEdge> */
    private array $edges = [];

    private ?string $reducerClass = null;

    private ?string $workflowName = null;

    /** @var string[] */
    private array $interruptBefore = [];

    /** @var string[] */
    private array $interruptAfter = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): CompiledWorkflow
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $edges = array_map(function (array $edgeData): Edge|BranchEdge {
            return match ($edgeData['type']) {
                'edge' => Edge::fromArray($edgeData),
                'branch' => BranchEdge::fromArray($edgeData),
                default => throw new \InvalidArgumentException("Unknown edge type [{$edgeData['type']}]."),
            };
        }, $data['edges'] ?? []);

        return new CompiledWorkflow(
            nodes:           $data['nodes'] ?? [],
            edges:           $edges,
            reducerClass:    $data['reducerClass'] ?? null,
            interruptBefore: $data['interruptBefore'] ?? [],
            interruptAfter:  $data['interruptAfter'] ?? [],
            workflowName:    $data['workflowName'] ?? null,
        );
    }

    public function addNode(string $name, string|Node $node): self
    {
        $this->nodes[$name] = $node;

        return $this;
    }

    public function transition(string $from, string $to, \Closure|string|null $when = null): self
    {
        $this->edges[] = new Edge($from, $to, $when);

        return $this;
    }

    /**
     * @param string[] $targets Possible destination node names for visualization (optional but recommended).
     */
    public function branch(string $from, \Closure|string $resolver, array $targets = []): self
    {
        $this->edges[] = new BranchEdge($from, $resolver, $targets);

        return $this;
    }

    public function withReducer(string $reducerClass): self
    {
        $this->reducerClass = $reducerClass;

        return $this;
    }

    public function withName(string $name): self
    {
        $this->workflowName = $name;

        return $this;
    }

    /**
     * Pause execution BEFORE the given node(s) run. On resume the node executes.
     *
     * @param string ...$nodeNames
     */
    public function interruptBefore(string ...$nodeNames): self
    {
        $this->interruptBefore = array_merge($this->interruptBefore, $nodeNames);

        return $this;
    }

    /**
     * Pause execution AFTER the given node(s) complete. On resume edges evaluate.
     *
     * @param string ...$nodeNames
     */
    public function interruptAfter(string ...$nodeNames): self
    {
        $this->interruptAfter = array_merge($this->interruptAfter, $nodeNames);

        return $this;
    }

    public function compile(): CompiledWorkflow
    {
        $this->validate();

        return new CompiledWorkflow(
            nodes:           $this->nodes,
            edges:           $this->edges,
            reducerClass:    $this->reducerClass,
            interruptBefore: $this->interruptBefore,
            interruptAfter:  $this->interruptAfter,
            workflowName:    $this->workflowName,
        );
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
                if (!in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("BranchEdge references unknown 'from' node [{$edge->from}].");
                }
            } else {
                if ($edge->to === self::START) {
                    throw new \InvalidArgumentException('Edges to __START__ are not allowed.');
                }
                if (!in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'from' node [{$edge->from}].");
                }
                if (!in_array($edge->to, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'to' node [{$edge->to}].");
                }
            }
        }

        if (!$hasStartEdge) {
            throw new \InvalidArgumentException('Workflow must have at least one edge from __START__.');
        }
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        foreach ($this->edges as $edge) {
            if (!$edge->isSerializable()) {
                throw new \RuntimeException('Cannot serialize workflow: one or more edges contain Closure conditions.');
            }
        }

        $serializedNodes = array_map(function ($node) {
            return is_string($node) ? $node : $node::class;
        }, $this->nodes);

        $serializedEdges = array_map(fn($edge) => $edge->toArray(), $this->edges);

        return json_encode([
            'nodes'           => $serializedNodes,
            'edges'           => $serializedEdges,
            'reducerClass'    => $this->reducerClass,
            'workflowName'    => $this->workflowName,
            'interruptBefore' => $this->interruptBefore,
            'interruptAfter'  => $this->interruptAfter,
        ], JSON_THROW_ON_ERROR);
    }
}
