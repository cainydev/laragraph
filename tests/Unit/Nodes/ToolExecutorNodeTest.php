<?php

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Integrations\Prism\ToolExecutor;
use Prism\Prism\Tool;

use function Cainy\Laragraph\Tests\makeContext;

// Register a fake parent class with tools
beforeEach(function () {
    $this->parentClass = new class implements Node
    {
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
    $node = new ToolExecutor('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__loop__'), $state);

    expect($mutation['messages'])->toHaveCount(1);
    expect($mutation['messages'][0]['type'])->toBe('tool_result');
    expect($mutation['messages'][0]['tool_results'])->toHaveCount(1);
    expect($mutation['messages'][0]['tool_results'][0]['result'])->toBe('5');
    expect($mutation['messages'][0]['tool_results'][0]['tool_call_id'])->toBe('tc1');
    expect($mutation['messages'][0]['tool_results'][0]['tool_name'])->toBe('add');
});

it('returns error for unknown tool', function () {
    $node = new ToolExecutor('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'unknown_tool', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__loop__'), $state);

    expect($mutation['messages'][0]['tool_results'][0]['result'])->toContain('not found');
});

it('serializes and deserializes correctly', function () {
    $node = new ToolExecutor('agent', $this->parentClass::class);

    $array = $node->toArray();

    expect($array['__synthetic'])->toBe('tool_executor');
    expect($array['parent_node_name'])->toBe('agent');
    expect($array['parent_node_class'])->toBe($this->parentClass::class);

    $restored = ToolExecutor::fromArray($array);

    expect($restored->getParentNodeName())->toBe('agent');
    expect($restored->getParentNodeClass())->toBe($this->parentClass::class);
    expect($restored->name())->toBe('agent.tools');
});

it('returns empty when no tool_calls in last message', function () {
    $node = new ToolExecutor('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => 'done', 'tool_calls' => []],
        ],
    ];

    expect($node->handle(makeContext(nodeName: 'agent.__loop__'), $state))->toBe([]);
});

it('returns empty when no messages in state', function () {
    $node = new ToolExecutor('agent', $this->parentClass::class);

    expect($node->handle(makeContext(nodeName: 'agent.__loop__'), []))->toBe([]);
});

it('executes multiple tool calls in one message', function () {
    $node = new ToolExecutor('agent', $this->parentClass::class);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 1, 'b' => 2]],
                ['id' => 'tc2', 'name' => 'greet', 'arguments' => ['name' => 'Alice']],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(nodeName: 'agent.__loop__'), $state);

    expect($mutation['messages'][0]['tool_results'])->toHaveCount(2);
    expect($mutation['messages'][0]['tool_results'][0]['result'])->toBe('3');
    expect($mutation['messages'][0]['tool_results'][1]['result'])->toBe('Hello, Alice!');
});
