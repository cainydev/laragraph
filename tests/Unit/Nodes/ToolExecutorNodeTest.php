<?php

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Nodes\ToolExecutorNode;
use Prism\Prism\Tool;

use function Cainy\Laragraph\Tests\makeContext;

// Register a fake parent class with tools
beforeEach(function () {
    $this->parentClass = new class implements Node
    {
        public int $maxIterations = 3;

        public function handle(NodeExecutionContext $context, array $state): array
        {
            return [];
        }

        public function tools(): array
        {
            return [
                (new Tool)->as('add')->for('Add two numbers')
                    ->withNumberParameter('a', 'First number')
                    ->withNumberParameter('b', 'Second number')
                    ->using(fn (int|float $a, int|float $b): string => (string) ($a + $b)),
                (new Tool)->as('greet')->for('Greet someone')
                    ->withStringParameter('name', 'Name')
                    ->using(fn (string $name): string => "Hello, {$name}!"),
            ];
        }
    };

    app()->bind($this->parentClass::class, fn () => $this->parentClass);
});

it('executes Prism Tool objects correctly', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]],
            ]],
        ],
        '__agent_iterations' => 0,
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__tools__'), $state);

    expect($mutation['messages'])->toHaveCount(1);
    expect($mutation['messages'][0]['type'])->toBe('tool_result');
    expect($mutation['messages'][0]['tool_results'])->toHaveCount(1);
    expect($mutation['messages'][0]['tool_results'][0]['result'])->toBe('5');
    expect($mutation['messages'][0]['tool_results'][0]['tool_call_id'])->toBe('tc1');
    expect($mutation['messages'][0]['tool_results'][0]['tool_name'])->toBe('add');
});

it('returns error for unknown tool', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'unknown_tool', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__tools__'), $state);

    expect($mutation['messages'][0]['tool_results'][0]['result'])->toContain('not found');
});

it('increments iteration counter', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'greet', 'arguments' => ['name' => 'World']],
            ]],
        ],
        '__agent_iterations' => 1,
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__tools__'), $state);

    expect($mutation['__agent_iterations'])->toBe(2);
});

it('enforces max iterations', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 1, 'b' => 1]],
            ]],
        ],
        '__agent_iterations' => 3, // equals maxIterations
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__tools__'), $state);

    // Should return an assistant message breaking the loop (no tool_calls)
    expect($mutation['messages'][0]['type'])->toBe('assistant');
    expect($mutation['messages'][0]['content'])->toContain('Maximum tool iterations');
    expect($mutation['messages'][0]['tool_calls'])->toBeEmpty();
});

it('serializes and deserializes correctly', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $array = $node->toArray();

    expect($array['__synthetic'])->toBe('tool_executor');
    expect($array['parent_node_name'])->toBe('agent');
    expect($array['parent_node_class'])->toBe($this->parentClass::class);

    $restored = ToolExecutorNode::fromArray($array);

    expect($restored->getParentNodeName())->toBe('agent');
    expect($restored->getParentNodeClass())->toBe($this->parentClass::class);
    expect($restored->name())->toBe('agent.tools');
});

it('returns empty when no tool_calls in last message', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => 'done', 'tool_calls' => []],
        ],
    ];

    expect($node->handle(makeContext(nodeName: 'agent.__tools__'), $state))->toBe([]);
});

it('returns empty when no messages in state', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    expect($node->handle(makeContext(nodeName: 'agent.__tools__'), []))->toBe([]);
});

it('executes multiple tool calls in one message', function () {
    $node = new ToolExecutorNode('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 1, 'b' => 2]],
                ['id' => 'tc2', 'name' => 'greet', 'arguments' => ['name' => 'Alice']],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__tools__'), $state);

    expect($mutation['messages'][0]['tool_results'])->toHaveCount(2);
    expect($mutation['messages'][0]['tool_results'][0]['result'])->toBe('3');
    expect($mutation['messages'][0]['tool_results'][1]['result'])->toBe('Hello, Alice!');
});
