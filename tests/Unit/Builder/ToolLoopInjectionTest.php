<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Nodes\ToolExecutorNode;
use Prism\Prism\Tool;

// A fake node with tools for testing
function makeToolUsingNode(): Node
{
    return new class implements Node
    {
        public int $maxIterations = 10;

        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['messages' => [['type' => 'assistant', 'content' => 'hi', 'tool_calls' => [], 'additional_content' => []]]];
        }

        public function tools(): array
        {
            return [
                (new Tool)->as('test_tool')->for('A test tool')->using(fn (): string => 'ok'),
            ];
        }
    };
}

// A fake node without tools
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

it('injects __tools__ node for tool-using nodes', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeToolUsingNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent.__tools__');
    expect($nodes['agent.__tools__'])->toBeInstanceOf(ToolExecutorNode::class);
});

it('does not inject __tools__ for nodes without tools', function () {
    $compiled = Workflow::create()
        ->addNode('simple', makeSimpleNode())
        ->transition(Workflow::START, 'simple')
        ->transition('simple', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->not->toHaveKey('simple.__tools__');
    expect($nodes)->toHaveCount(1);
});

it('adds tool loop edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeToolUsingNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

    $edges = $compiled->getEdges();

    // Should have: START→agent, agent→END (guarded), agent→agent.__tools__ (has_tool_calls), agent.__tools__→agent
    $edgeDescriptions = array_map(function ($edge) {
        if ($edge instanceof Edge) {
            return "{$edge->from}→{$edge->to}";
        }

        return "{$edge->from}→branch";
    }, $edges);

    expect($edgeDescriptions)->toContain('agent→agent.__tools__');
    expect($edgeDescriptions)->toContain('agent.__tools__→agent');
});

it('guards existing unconditional edges with !has_tool_calls', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeToolUsingNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END)
        ->compile();

    // The agent→END edge should now be guarded — it should NOT fire when tool_calls exist
    $state = ['messages' => [['type' => 'assistant', 'content' => 'hi', 'tool_calls' => [['id' => 'tc1', 'name' => 'test', 'arguments' => []]]]]];
    $nextNodes = $compiled->resolveNextNodes('agent', $state);

    // Should route to tools, not END
    expect($nextNodes)->toContain('agent.__tools__');
    expect($nextNodes)->not->toContain(Workflow::END);

    // Without tool_calls, should route to END
    $stateNoTools = ['messages' => [['type' => 'assistant', 'content' => 'done', 'tool_calls' => []]]];
    $nextNoTools = $compiled->resolveNextNodes('agent', $stateNoTools);

    expect($nextNoTools)->toContain(Workflow::END);
    expect($nextNoTools)->not->toContain('agent.__tools__');
});

it('guards existing expression edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeToolUsingNode())
        ->addNode('next', makeSimpleNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', 'next', 'state["ready"] == true')
        ->transition('agent', Workflow::END)
        ->compile();

    // With tool_calls, neither edge should fire (both guarded)
    $stateWithTools = [
        'messages' => [['type' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tc1', 'name' => 'x', 'arguments' => []]]]],
        'ready' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateWithTools);
    expect($next)->toContain('agent.__tools__');
    expect($next)->not->toContain('next');
});

it('handles multiple tool-using nodes in one graph', function () {
    $compiled = Workflow::create()
        ->addNode('agent1', makeToolUsingNode())
        ->addNode('agent2', makeToolUsingNode())
        ->transition(Workflow::START, 'agent1')
        ->transition('agent1', 'agent2')
        ->transition('agent2', Workflow::END)
        ->compile();

    $nodes = $compiled->getNodes();

    expect($nodes)->toHaveKey('agent1.__tools__');
    expect($nodes)->toHaveKey('agent2.__tools__');
});

it('toolNode helper returns correct name', function () {
    expect(Workflow::toolNode('agent'))->toBe('agent.__tools__');
    expect(Workflow::toolNode('my-node'))->toBe('my-node.__tools__');
});

it('guards closure-based edges', function () {
    $compiled = Workflow::create()
        ->addNode('agent', makeToolUsingNode())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END, fn (array $state): bool => ($state['done'] ?? false) === true)
        ->compile();

    // With tool_calls, closure edge should not fire
    $stateWithTools = [
        'messages' => [['type' => 'assistant', 'tool_calls' => [['id' => 'tc1', 'name' => 'x', 'arguments' => []]]]],
        'done' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateWithTools);
    expect($next)->toContain('agent.__tools__');
    expect($next)->not->toContain(Workflow::END);

    // Without tool_calls and done=true, should go to END
    $stateDone = [
        'messages' => [['type' => 'assistant', 'content' => 'done', 'tool_calls' => []]],
        'done' => true,
    ];
    $next = $compiled->resolveNextNodes('agent', $stateDone);
    expect($next)->toContain(Workflow::END);
});
