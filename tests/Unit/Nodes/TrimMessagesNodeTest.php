<?php

use Cainy\Laragraph\Nodes\TrimMessagesNode;

use function Cainy\Laragraph\Tests\makeContext;

it('returns empty mutation when messages are within limit', function () {
    $node = new TrimMessagesNode(keep: 5);
    $state = ['messages' => [['role' => 'user', 'content' => 'hi']]];

    expect($node->handle(makeContext(), $state))->toBe([]);
});

it('returns empty mutation when messages key is absent', function () {
    $node = new TrimMessagesNode(keep: 5);

    expect($node->handle(makeContext(), []))->toBe([]);
});

it('trims to the last N messages when over the limit', function () {
    $node = new TrimMessagesNode(keep: 3);
    $messages = array_map(fn ($i) => ['role' => 'user', 'content' => "msg $i"], range(1, 6));

    $result = $node->handle(makeContext(), ['messages' => $messages]);

    expect($result['messages'])->toHaveCount(3);
    expect($result['messages'][0]['content'])->toBe('msg 4');
    expect($result['messages'][2]['content'])->toBe('msg 6');
});

it('reindexes the trimmed list', function () {
    $node = new TrimMessagesNode(keep: 2);
    $messages = array_map(fn ($i) => ['role' => 'user', 'content' => "msg $i"], range(1, 5));

    $result = $node->handle(makeContext(), ['messages' => $messages]);

    expect(array_keys($result['messages']))->toBe([0, 1]);
});

it('respects a custom key', function () {
    $node = new TrimMessagesNode(keep: 2, key: 'history');
    $history = array_map(fn ($i) => ['text' => "entry $i"], range(1, 4));

    $result = $node->handle(makeContext(), ['history' => $history]);

    expect($result['history'])->toHaveCount(2);
    expect($result['history'][0]['text'])->toBe('entry 3');
});
