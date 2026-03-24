<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Nodes\ToolExecutorNode;
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
        return new self;
    }

    public static function toolNode(string $nodeName): string
    {
        return $nodeName.'.__tools__';
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

        $nodes = [];
        foreach ($data['nodes'] ?? [] as $name => $nodeData) {
            if (is_array($nodeData) && ($nodeData['__synthetic'] ?? null) === 'tool_executor') {
                $nodes[$name] = ToolExecutorNode::fromArray($nodeData);
            } else {
                $nodes[$name] = $nodeData;
            }
        }

        return new CompiledWorkflow(
            nodes: $nodes,
            edges: $edges,
            reducerClass: $data['reducerClass'] ?? null,
            interruptBefore: $data['interruptBefore'] ?? [],
            interruptAfter: $data['interruptAfter'] ?? [],
            workflowName: $data['workflowName'] ?? null,
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
     * @param  string[]  $targets  Possible destination node names for visualization (optional but recommended).
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
     */
    public function interruptBefore(string ...$nodeNames): self
    {
        $this->interruptBefore = array_merge($this->interruptBefore, $nodeNames);

        return $this;
    }

    /**
     * Pause execution AFTER the given node(s) complete. On resume edges evaluate.
     */
    public function interruptAfter(string ...$nodeNames): self
    {
        $this->interruptAfter = array_merge($this->interruptAfter, $nodeNames);

        return $this;
    }

    public function compile(): CompiledWorkflow
    {
        $this->validate();

        $nodes = $this->nodes;
        $edges = $this->edges;

        $this->injectToolLoops($nodes, $edges);

        return new CompiledWorkflow(
            nodes: $nodes,
            edges: $edges,
            reducerClass: $this->reducerClass,
            interruptBefore: $this->interruptBefore,
            interruptAfter: $this->interruptAfter,
            workflowName: $this->workflowName,
        );
    }

    /**
     * @param  array<string, string|Node>  $nodes
     * @param  list<Edge|BranchEdge>  $edges
     */
    private function injectToolLoops(array &$nodes, array &$edges): void
    {
        $toolUsingNodes = [];

        foreach ($nodes as $name => $node) {
            if ($this->nodeHasTools($node)) {
                $toolUsingNodes[$name] = $node;
            }
        }

        foreach ($toolUsingNodes as $name => $node) {
            $toolNodeName = self::toolNode($name);
            $nodeClass = is_string($node) ? $node : $node::class;

            // Add the synthetic tool executor node
            $nodes[$toolNodeName] = new ToolExecutorNode($name, $nodeClass);

            // Guard existing edges FROM this node with !has_tool_calls
            $edges = array_map(function (Edge|BranchEdge $edge) use ($name): Edge|BranchEdge {
                if ($edge->from !== $name) {
                    return $edge;
                }

                if ($edge instanceof BranchEdge) {
                    return $this->guardBranchEdge($edge);
                }

                return $this->guardEdge($edge);
            }, $edges);

            // Add tool loop edges
            $edges[] = new Edge($name, $toolNodeName, 'has_tool_calls(state["messages"])');
            $edges[] = new Edge($toolNodeName, $name);
        }
    }

    private function nodeHasTools(string|Node $node): bool
    {
        if ($node instanceof Node) {
            return method_exists($node, 'tools') && ! empty($node->tools());
        }

        // Class-string: trust that the method exists, can't call it without instantiation
        return is_a($node, Node::class, true) && method_exists($node, 'tools');
    }

    private function guardEdge(Edge $edge): Edge
    {
        if ($edge->when === null) {
            return new Edge($edge->from, $edge->to, '!has_tool_calls(state["messages"])');
        }

        if ($edge->when instanceof \Closure) {
            $original = $edge->when;

            return new Edge($edge->from, $edge->to, function (array $state) use ($original): bool {
                $last = ! empty($state['messages']) ? end($state['messages']) : null;
                if (! empty($last['tool_calls'])) {
                    return false;
                }

                return (bool) $original($state);
            });
        }

        return new Edge($edge->from, $edge->to, '!has_tool_calls(state["messages"]) and ('.$edge->when.')');
    }

    private function guardBranchEdge(BranchEdge $edge): BranchEdge
    {
        if ($edge->resolver instanceof \Closure) {
            $original = $edge->resolver;

            return new BranchEdge($edge->from, function (array $state) use ($original): array {
                $last = ! empty($state['messages']) ? end($state['messages']) : null;
                if (! empty($last['tool_calls'])) {
                    return [];
                }

                $result = $original($state);

                return is_array($result) ? $result : [(string) $result];
            }, $edge->targets);
        }

        // String expression: wrap with guard
        $guardedResolver = '!has_tool_calls(state["messages"]) ? ('.$edge->resolver.') : []';

        return new BranchEdge($edge->from, $guardedResolver, $edge->targets);
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

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        foreach ($this->edges as $edge) {
            if (! $edge->isSerializable()) {
                throw new \RuntimeException('Cannot serialize workflow: one or more edges contain Closure conditions.');
            }
        }

        $serializedNodes = array_map(function ($node) {
            if ($node instanceof ToolExecutorNode) {
                return $node->toArray();
            }

            return is_string($node) ? $node : $node::class;
        }, $this->nodes);

        $serializedEdges = array_map(fn ($edge) => $edge->toArray(), $this->edges);

        return json_encode([
            'nodes' => $serializedNodes,
            'edges' => $serializedEdges,
            'reducerClass' => $this->reducerClass,
            'workflowName' => $this->workflowName,
            'interruptBefore' => $this->interruptBefore,
            'interruptAfter' => $this->interruptAfter,
        ], JSON_THROW_ON_ERROR);
    }
}
