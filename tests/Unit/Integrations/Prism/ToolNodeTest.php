<?php

use Cainy\Laragraph\Integrations\Prism\ToolNode;

use function Cainy\Laragraph\Tests\makeContext;

function makePrismToolNode(array $tools): ToolNode
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

it('executes matching tool and returns tool_result message', function () {
    $node = makePrismToolNode([
        'add' => fn (array $args) => $args['a'] + $args['b'],
    ]);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'add', 'arguments' => ['a' => 2, 'b' => 3]],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'])->toHaveCount(1);
    expect($mutation['messages'][0]['type'])->toBe('tool_result');
    expect($mutation['messages'][0]['tool_results'])->toHaveCount(1);
    expect($mutation['messages'][0]['tool_results'][0]['tool_call_id'])->toBe('tc1');
    expect($mutation['messages'][0]['tool_results'][0]['tool_name'])->toBe('add');
    expect($mutation['messages'][0]['tool_results'][0]['result'])->toBe('5');
});

it('returns not-found message for unknown tool', function () {
    $node = makePrismToolNode([]);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'unknown_tool', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'][0]['tool_results'][0]['result'])->toContain('not found');
});

it('catches tool exceptions and returns error message', function () {
    $node = makePrismToolNode([
        'fail' => fn () => throw new RuntimeException('boom'),
    ]);

    $state = [
        'messages' => [
            ['type' => 'assistant', 'tool_calls' => [
                ['id' => 'tc1', 'name' => 'fail', 'arguments' => []],
            ]],
        ],
    ];

    $mutation = $node->handle(makeContext(), $state);

    expect($mutation['messages'][0]['tool_results'][0]['result'])->toContain('boom');
});

it('returns empty when no messages in state', function () {
    $node = makePrismToolNode(['x' => fn () => 'ok']);

    expect($node->handle(makeContext(), []))->toBe([]);
});

it('returns empty when last message has no tool_calls', function () {
    $node = makePrismToolNode(['x' => fn () => 'ok']);

    $state = ['messages' => [['type' => 'assistant', 'content' => 'hello']]];

    expect($node->handle(makeContext(), $state))->toBe([]);
});
