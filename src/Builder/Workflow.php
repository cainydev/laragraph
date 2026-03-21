<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\NodeInterface;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;

class Workflow
{
    public const string START = '__START__';
    public const string END = '__END__';

    /** @var array<string, string|NodeInterface> */
    private array $nodes = [];

    /** @var list<Edge|BranchEdge> */
    private array $edges = [];

    private ?string $reducerClass = null;

    public static function create(): self
    {
        return new self();
    }

    public function addNode(string $name, string|NodeInterface $node): self
    {
        $this->nodes[$name] = $node;

        return $this;
    }

    public function transition(string $from, string $to, \Closure|string|null $when = null): self
    {
        $this->edges[] = new Edge($from, $to, $when);

        return $this;
    }

    public function branch(string $from, \Closure|string $resolver): self
    {
        $this->edges[] = new BranchEdge($from, $resolver);

        return $this;
    }

    public function withReducer(string $reducerClass): self
    {
        $this->reducerClass = $reducerClass;

        return $this;
    }

    public function compile(): CompiledWorkflow
    {
        $this->validate();

        return new CompiledWorkflow(
            nodes: $this->nodes,
            edges: $this->edges,
            reducerClass: $this->reducerClass,
        );
    }

    private function validate(): void
    {
        $nodeNames = array_keys($this->nodes);
        $pseudoNodes = [self::START, self::END];
        $allNodes = array_merge($nodeNames, $pseudoNodes);

        foreach ($this->edges as $edge) {
            if ($edge instanceof BranchEdge) {
                if (! in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("BranchEdge references unknown 'from' node [{$edge->from}].");
                }
            } else {
                if (! in_array($edge->from, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'from' node [{$edge->from}].");
                }
                if (! in_array($edge->to, $allNodes, true)) {
                    throw new \InvalidArgumentException("Edge references unknown 'to' node [{$edge->to}].");
                }
            }
        }
    }

    public function toJson(): string
    {
        foreach ($this->edges as $edge) {
            if (! $edge->isSerializable()) {
                throw new \RuntimeException('Cannot serialize workflow: one or more edges contain Closure conditions.');
            }
        }

        $serializedNodes = [];
        foreach ($this->nodes as $name => $node) {
            $serializedNodes[$name] = is_string($node) ? $node : $node::class;
        }

        $serializedEdges = array_map(fn ($edge) => $edge->toArray(), $this->edges);

        return json_encode([
            'nodes'        => $serializedNodes,
            'edges'        => $serializedEdges,
            'reducerClass' => $this->reducerClass,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): CompiledWorkflow
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $edges = array_map(function (array $edgeData): Edge|BranchEdge {
            return match ($edgeData['type']) {
                'edge'   => Edge::fromArray($edgeData),
                'branch' => BranchEdge::fromArray($edgeData),
                default  => throw new \InvalidArgumentException("Unknown edge type [{$edgeData['type']}]."),
            };
        }, $data['edges'] ?? []);

        return new CompiledWorkflow(
            nodes: $data['nodes'] ?? [],
            edges: $edges,
            reducerClass: $data['reducerClass'] ?? null,
        );
    }
}
