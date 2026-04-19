<?php

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Integrations\Prism\PrismToolNode;
use Cainy\Laragraph\Integrations\Prism\ToolExecutor;

it('PrismToolNode implements HasLoop', function () {
    $node = new PrismToolNode;
    expect($node)->toBeInstanceOf(HasLoop::class);
});

it('returns a ToolExecutor as the loop node', function () {
    $node = new PrismToolNode;
    $loop = $node->loopNode('agent');
    expect($loop)->toBeInstanceOf(ToolExecutor::class);
});

it('loopCondition fires when the last message has tool_calls', function () {
    $node = new PrismToolNode;
    $cond = $node->loopCondition();

    $state = [
        'messages' => [
            ['type' => 'assistant', 'tool_calls' => [['id' => '1', 'name' => 'search', 'arguments' => []]]],
        ],
    ];
    expect($cond($state))->toBeTrue();

    $stateDone = [
        'messages' => [
            ['type' => 'assistant', 'content' => 'final answer', 'tool_calls' => []],
        ],
    ];
    expect($cond($stateDone))->toBeFalse();
});

it('loopCondition respects a custom messagesKey()', function () {
    $node = new class extends PrismToolNode
    {
        public function messagesKey(): string
        {
            return 'chat';
        }
    };
    $cond = $node->loopCondition();

    $state = ['chat' => [
        ['type' => 'assistant', 'tool_calls' => [['id' => 'x', 'name' => 'y', 'arguments' => []]]],
    ]];
    expect($cond($state))->toBeTrue();

    // Legacy 'messages' key should not trigger for this node.
    $stateOther = ['messages' => [
        ['type' => 'assistant', 'tool_calls' => [['id' => 'x', 'name' => 'y', 'arguments' => []]]],
    ]];
    expect($cond($stateOther))->toBeFalse();
});
