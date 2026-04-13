<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Integrations\Prism\ToolExecutor;

// A fake node that implements HasLoop for testing
function makeLoopNode(): Node&HasLoop
{
    return new class implements HasLoop, Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['messages' => [['type' => 'assistant', 'content' => 'hi', 'tool_calls' => [], 'additional_content' => []]]];
        }

        public function loopNode(string $nodeName): Node
        {
            return new ToolExecutor($nodeName, self::class);
        }

        public function loopCondition(): Closure
        {
            return function (array $state): bool {
                $messages = $state['messages'] ?? [];
                $last = ! empty($messages) ? end($messages) : null;

                return ! empty($last['tool_calls'] ?? []);
            };
        }
    };
}

// A fake node without HasLoop
function makeSimpleNode(): Node
{
    return new class implements Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['result' => 'done'];
        }
    };
}

it('injects __loop__ node for HasLoop nodes', function () {
    $loopNode = makeLoopNode();
    $compiled = (new class($loopNode) extends Workflow
    {
        public function __construct(private readonly Node $loopNode) {}

        public function definition(): void
        {
            $this->addNode('agent', $this->loopNode);
            $this->transition(Workflow::START, 'agent');
            $this->transition('agent', Workflow::END);
        }
    })->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent.__loop__');
    expect($nodes['agent.__loop__'])->toBeInstanceOf(ToolExecutor::class);
});

it('does not inject __loop__ for nodes without HasLoop', function () {
    $simpleNode = makeSimpleNode();
    $compiled = (new class($simpleNode) extends Workflow
    {
        public function __construct(private readonly Node $simpleNode) {}

        public function definition(): void
        {
            $this->addNode('simple', $this->simpleNode);
            $this->transition(Workflow::START, 'simple');
            $this->transition('simple', Workflow::END);
        }
    })->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->not->toHaveKey('simple.__loop__');
    expect($nodes)->toHaveCount(1);
});

it('adds loop edges', function () {
    $loopNode = makeLoopNode();
    $compiled = (new class($loopNode) extends Workflow
    {
        public function __construct(private readonly Node $loopNode) {}

        public function definition(): void
        {
            $this->addNode('agent', $this->loopNode);
            $this->transition(Workflow::START, 'agent');
            $this->transition('agent', Workflow::END);
        }
    })->compile();

    $edges = $compiled->getEdges();

    $edgeDescriptions = array_map(function ($edge) {
        if ($edge instanceof Edge) {
            return "{$edge->from}→{$edge->to}";
        }

        return "{$edge->from}→branch";
    }, $edges);

    expect($edgeDescriptions)->toContain('agent→agent.__loop__');
    expect($edgeDescriptions)->toContain('agent.__loop__→agent');
});

it('guards existing unconditional edges with negated loop condition', function () {
    $loopNode = makeLoopNode();
    $compiled = (new class($loopNode) extends Workflow
    {
        public function __construct(private readonly Node $loopNode) {}

        public function definition(): void
        {
            $this->addNode('agent', $this->loopNode);
            $this->transition(Workflow::START, 'agent');
            $this->transition('agent', Workflow::END);
        }
    })->compile();

    // When loop condition is true (tool_calls non-empty), should route to loop node, not END
    $stateWithTools = ['messages' => [['type' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc1', 'name' => 'test', 'arguments' => []]]]]];
    $nextNodes = $compiled->resolveNextNodes('agent', $stateWithTools);

    expect($nextNodes)->toContain('agent.__loop__');
    expect($nextNodes)->not->toContain(Workflow::END);

    // When loop condition is false (no tool_calls), should route to END
    $stateNoTools = ['messages' => [['type' => 'assistant', 'content' => 'done', 'tool_calls' => []]]];
    $nextNoTools = $compiled->resolveNextNodes('agent', $stateNoTools);

    expect($nextNoTools)->toContain(Workflow::END);
    expect($nextNoTools)->not->toContain('agent.__loop__');
});

it('handles multiple HasLoop nodes in one graph', function () {
    $loopNode1 = makeLoopNode();
    $loopNode2 = makeLoopNode();
    $compiled = (new class($loopNode1, $loopNode2) extends Workflow
    {
        public function __construct(
            private readonly Node $loopNode1,
            private readonly Node $loopNode2,
        ) {}

        public function definition(): void
        {
            $this->addNode('agent1', $this->loopNode1);
            $this->addNode('agent2', $this->loopNode2);
            $this->transition(Workflow::START, 'agent1');
            $this->transition('agent1', 'agent2');
            $this->transition('agent2', Workflow::END);
        }
    })->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent1.__loop__');
    expect($nodes)->toHaveKey('agent2.__loop__');
});

it('toolNode helper returns correct name', function () {
    expect(Workflow::toolNode('agent'))->toBe('agent.__loop__');
    expect(Workflow::toolNode('my-node'))->toBe('my-node.__loop__');
});

it('guards closure-based edges', function () {
    $loopNode = makeLoopNode();
    $compiled = (new class($loopNode) extends Workflow
    {
        public function __construct(private readonly Node $loopNode) {}

        public function definition(): void
        {
            $this->addNode('agent', $this->loopNode);
            $this->transition(Workflow::START, 'agent');
            $this->transition('agent', Workflow::END, fn (array $state): bool => ($state['done'] ?? false) === true);
        }
    })->compile();

    // With tool_calls, closure edge should not fire
    $stateWithTools = [
        'messages' => [['type' => 'assistant', 'tool_calls' => [['id' => 'tc1', 'name' => 'x', 'arguments' => []]]]],
        'done' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateWithTools);
    expect($next)->toContain('agent.__loop__');
    expect($next)->not->toContain(Workflow::END);

    // Without tool_calls and done=true, should go to END
    $stateDone = [
        'messages' => [['type' => 'assistant', 'content' => 'done', 'tool_calls' => []]],
        'done' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateDone);
    expect($next)->toContain(Workflow::END);
});
