<?php

use Cainy\Laragraph\Exceptions\NodeSkippedException;
use Cainy\Laragraph\Nodes\ReduceNode;

use function Cainy\Laragraph\Tests\makeContext;

it('passes through when collected count meets expected', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 3);

    $state = ['results' => ['a', 'b', 'c']];
    expect($node->handle(makeContext(), $state))->toBe([]);
});

it('skips when collected count is below expected', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 3);

    $state = ['results' => ['a', 'b']];

    expect(fn () => $node->handle(makeContext(), $state))->toThrow(NodeSkippedException::class);
});

it('skips when other fan-in arrivals are still pending', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 3);

    $state = ['results' => ['a', 'b', 'c']];

    expect(fn () => $node->handle(makeContext(pendingCount: 2), $state))->toThrow(NodeSkippedException::class);
});

it('reads expected count from a state key when expectedCount is 0', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0, countFromKey: 'total');

    $state = ['results' => ['x', 'y'], 'total' => 2];
    expect($node->handle(makeContext(), $state))->toBe([]);
});

it('pauses when dynamic count not yet met', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0, countFromKey: 'total');

    $state = ['results' => ['x'], 'total' => 3];
    expect(fn () => $node->handle(makeContext(), $state))->toThrow(NodeSkippedException::class);
});

it('passes through when collect key is absent and expectedCount is 0', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0);

    expect($node->handle(makeContext(), []))->toBe([]);
});
