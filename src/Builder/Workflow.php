<?php

namespace Cainy\Laragraph\Builder;

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Engine\Concerns\EvaluatesExpressions;
use Cainy\Laragraph\Nodes\GateNode;
use Cainy\Laragraph\Nodes\MapNode;
use Cainy\Laragraph\Nodes\ReduceNode;
use Cainy\Laragraph\Nodes\HttpNode;
use Cainy\Laragraph\Nodes\DelayNode;
use Cainy\Laragraph\Nodes\CacheNode;
use Cainy\Laragraph\Nodes\NotifyNode;
use Cainy\Laragraph\Integrations\Prism\ToolExecutor;
use JsonException;

class Workflow
{
    use EvaluatesExpressions;
    public const string START = '__START__';

    public const string END = '__END__';

    /** @var array<string, string|Node> */
    private array $nodes = [];

    /** @var list<Edge|BranchEdge> */
    private array $edges = [];

    private ?string $reducerClass = null;

    private ?string $workflowName = null;

    private ?int $recursionLimit = null;

    /** @var string[] */
    private array $interruptBefore = [];

    /** @var string[] */
    private array $interruptAfter = [];

    /**
     * Registry mapping __synthetic type names to node classes.
     *
     * @var array<string, class-string<SerializableNode>>
     */
    private static array $syntheticTypes = [
        'tool_executor' => ToolExecutor::class,
        'gate' => GateNode::class,
        'map' => MapNode::class,
        'reduce' => ReduceNode::class,
        'http' => HttpNode::class,
        'delay' => DelayNode::class,
        'cache' => CacheNode::class,
        'notify' => NotifyNode::class,
    ];

    public static function create(): self
    {
        return new self;
    }

    public static function toolNode(string $nodeName): string
    {
        return $nodeName . '.__loop__';
    }

    /**
     * Register a custom synthetic node type for JSON serialization/deserialization.
     *
     * @param  class-string<SerializableNode>  $nodeClass
     */
    public static function registerSyntheticType(string $typeName, string $nodeClass): void
    {
        self::$syntheticTypes[$typeName] = $nodeClass;
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
            if (is_array($nodeData) && isset($nodeData['__synthetic'])) {
                $typeName = $nodeData['__synthetic'];
                $nodeClass = self::$syntheticTypes[$typeName]
                    ?? throw new \InvalidArgumentException("Unknown synthetic node type [{$typeName}].");
                $nodes[$name] = $nodeClass::fromArray($nodeData);
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
            recursionLimit: $data['recursionLimit'] ?? null,
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
     * Set the maximum number of node executions before the workflow is marked as failed.
     * Defaults to config('laragraph.recursion_limit', 25).
     */
    public function withRecursionLimit(int $limit): self
    {
        $this->recursionLimit = $limit;

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

        $this->injectLoops($nodes, $edges);

        return new CompiledWorkflow(
            nodes: $nodes,
            edges: $edges,
            reducerClass: $this->reducerClass,
            interruptBefore: $this->interruptBefore,
            interruptAfter: $this->interruptAfter,
            workflowName: $this->workflowName,
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

            $loopNodeName = $name . '.__loop__';
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

    private function guardEdge(Edge $edge, string|\Closure $condition): Edge
    {
        if ($condition instanceof \Closure) {
            $original = $edge->when;

            return new Edge($edge->from, $edge->to, function (array $state) use ($condition, $original): bool {
                if ($condition($state)) {
                    return false;
                }

                if ($original === null) {
                    return true;
                }

                if ($original instanceof \Closure) {
                    return (bool) $original($state);
                }

                return true;
            });
        }

        // String expression condition
        if ($edge->when === null) {
            return new Edge($edge->from, $edge->to, $this->negateExpression($condition));
        }

        if ($edge->when instanceof \Closure) {
            $original = $edge->when;
            $el = $this->makeExpressionLanguage();

            return new Edge($edge->from, $edge->to, function (array $state) use ($condition, $original, $el): bool {
                if ($el->evaluate($condition, ['state' => $state])) {
                    return false;
                }

                return (bool) $original($state);
            });
        }

        return new Edge($edge->from, $edge->to, $this->negateExpression($condition) . ' and (' . $edge->when . ')');
    }

    private function guardBranchEdge(BranchEdge $edge, string|\Closure $condition): BranchEdge
    {
        if ($condition instanceof \Closure) {
            $original = $edge->resolver;

            return new BranchEdge($edge->from, function (array $state) use ($condition, $original): array {
                if ($condition($state)) {
                    return [];
                }

                $result = ($original instanceof \Closure)
                    ? $original($state)
                    : $original;

                return is_array($result) ? $result : [(string) $result];
            }, $edge->targets);
        }

        if ($edge->resolver instanceof \Closure) {
            $original = $edge->resolver;
            $el = $this->makeExpressionLanguage();

            return new BranchEdge($edge->from, function (array $state) use ($condition, $original, $el): array {
                if ($el->evaluate($condition, ['state' => $state])) {
                    return [];
                }

                $result = $original($state);

                return is_array($result) ? $result : [(string) $result];
            }, $edge->targets);
        }

        // Both are strings
        return new BranchEdge($edge->from, $this->negateExpression($condition) . ' ? (' . $edge->resolver . ') : []', $edge->targets);
    }

    /**
     * Negate a string expression, simplifying not_empty/empty pairs.
     */
    private function negateExpression(string $condition): string
    {
        if (preg_match('/^not_empty\((.+)\)$/s', $condition, $m)) {
            return 'empty(' . $m[1] . ')';
        }

        if (preg_match('/^empty\((.+)\)$/s', $condition, $m)) {
            return 'not_empty(' . $m[1] . ')';
        }

        return 'not (' . $condition . ')';
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
        // Run loop injection on a copy before serializing (same as compile())
        $nodes = $this->nodes;
        $edges = $this->edges;
        $this->injectLoops($nodes, $edges);

        foreach ($edges as $edge) {
            if (! $edge->isSerializable()) {
                throw new \RuntimeException('Cannot serialize workflow: one or more edges contain Closure conditions.');
            }
        }

        $serializedNodes = array_map(function ($node) {
            if ($node instanceof SerializableNode) {
                return $node->toArray();
            }

            return is_string($node) ? $node : $node::class;
        }, $nodes);

        $serializedEdges = array_map(fn ($edge) => $edge->toArray(), $edges);

        return json_encode([
            'nodes' => $serializedNodes,
            'edges' => $serializedEdges,
            'reducerClass' => $this->reducerClass,
            'workflowName' => $this->workflowName,
            'recursionLimit' => $this->recursionLimit,
            'interruptBefore' => $this->interruptBefore,
            'interruptAfter' => $this->interruptAfter,
        ], JSON_THROW_ON_ERROR);
    }
}
