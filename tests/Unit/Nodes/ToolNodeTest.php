<?php

use Cainy\Laragraph\Nodes\ToolNode;

use function Cainy\Laragraph\Tests\makeContext;

function makeToolNode(array $tools): ToolNode
{
    return new class($tools) extends ToolNode
    {
        public function __construct(private readonly array $tools) {}

        protected function toolMap(): array
        {
            return $this->tools;
        }
    };
}

it('executes matching tool and returns messages', function () {
    $node = makeToolNode([
        'add' => fn (array $args) => $args['a'] + $args['b'],
    ]);

    $state = [
        'messages' => [
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'])->toHaveCount(1);
    expect($mutation['messages'][0]['role'])->toBe('tool');
    expect($mutation['messages'][0]['tool_use_id'])->toBe('tc1');
    expect($mutation['messages'][0]['content'])->toBe('5');
});

it('returns not-found message for unknown tool', function () {
    $node = makeToolNode([]);

    $state = [
        'messages' => [
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'unknown_tool', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'][0]['content'])->toContain('not found');
});

it('catches tool exceptions and returns error message', function () {
    $node = makeToolNode([
        'fail' => fn () => throw new RuntimeException('boom'),
    ]);

    $state = [
        'messages' => [
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'fail', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'][0]['content'])->toContain('boom');
});

it('returns empty when no messages in state', function () {
    $node = makeToolNode(['x' => fn () => 'ok']);

    expect($node->handle(makeContext(), []))->toBe([]);
});

it('returns empty when last message has no tool_calls', function () {
    $node = makeToolNode(['x' => fn () => 'ok']);

    $state = ['messages' => [['role' => 'assistant', 'content' => 'hello']]];

    expect($node->handle(makeContext(), $state))->toBe([]);
});
