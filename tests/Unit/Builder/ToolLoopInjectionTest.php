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
    return new class implements Node, HasLoop
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['messages' => [['type' => 'assistant', 'content' => 'hi', 'tool_calls' => [], 'additional_content' => []]]];
        }

        public function loopNode(string $nodeName): Node
        {
            return new ToolExecutor($nodeName, static::class);
        }

        public function loopCondition(): string|\Closure
        {
            return 'not_empty(last(state["messages"])["tool_calls"] ?? [])';
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
    $compiled = Workflow::create()
        ->addNode('agent', makeLoopNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent.__loop__');
    expect($nodes['agent.__loop__'])->toBeInstanceOf(ToolExecutor::class);
});

it('does not inject __loop__ for nodes without HasLoop', function () {
    $compiled = Workflow::create()
        ->addNode('simple', makeSimpleNode())
        ->transition(Workflow::START, 'simple')
        ->transition('simple', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->not->toHaveKey('simple.__loop__');
    expect($nodes)->toHaveCount(1);
});

it('adds loop edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeLoopNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

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
    $compiled = Workflow::create()
        ->addNode('agent', makeLoopNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

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

it('guards existing expression edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeLoopNode())
        ->addNode('next', makeSimpleNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', 'next', 'state["ready"] == true')
        ->transition('agent', Workflow::END)
        ->compile();

    // With tool_calls, neither exit edge should fire
    $stateWithTools = [
        'messages' => [['type' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc1', 'name' => 'x', 'arguments' => []]]]],
        'ready' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateWithTools);
    expect($next)->toContain('agent.__loop__');
    expect($next)->not->toContain('next');
});

it('handles multiple HasLoop nodes in one graph', function () {
    $compiled = Workflow::create()
        ->addNode('agent1', makeLoopNode())
        ->addNode('agent2', makeLoopNode())
        ->transition(Workflow::START, 'agent1')
        ->transition('agent1', 'agent2')
        ->transition('agent2', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent1.__loop__');
    expect($nodes)->toHaveKey('agent2.__loop__');
});

it('toolNode helper returns correct name', function () {
    expect(Workflow::toolNode('agent'))->toBe('agent.__loop__');
    expect(Workflow::toolNode('my-node'))->toBe('my-node.__loop__');
});

it('guards closure-based edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeLoopNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END, fn (array $state): bool => ($state['done'] ?? false) === true)
        ->compile();

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
