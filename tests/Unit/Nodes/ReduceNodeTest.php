<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\ReduceNode;

use function Cainy\Laragraph\Tests\makeContext;

it('passes through when collected count meets expected', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 3);

    $state = ['results' => ['a', 'b', 'c']];
    expect($node->handle(makeContext(), $state))->toBe([]);
});

it('pauses when collected count is below expected', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 3);

    $state = ['results' => ['a', 'b']]; // only 2 of 3

    expect(fn () => $node->handle(makeContext(), $state))->toThrow(NodePausedException::class);
});

it('reads expected count from a state key when expectedCount is 0', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0, countFromKey: 'total');

    $state = ['results' => ['x', 'y'], 'total' => 2];
    expect($node->handle(makeContext(), $state))->toBe([]);
});

it('pauses when dynamic count not yet met', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0, countFromKey: 'total');

    $state = ['results' => ['x'], 'total' => 3];
    expect(fn () => $node->handle(makeContext(), $state))->toThrow(NodePausedException::class);
});

it('passes through when collect key is absent and expectedCount is 0', function () {
    $node = new ReduceNode(collectKey: 'results', expectedCount: 0);

    // 0 collected >= 0 expected
    expect($node->handle(makeContext(), []))->toBe([]);
});

it('serializes to array', function () {
    $node = new ReduceNode('results', 3, 'count_key');

    expect($node->toArray())->toBe([
        '__synthetic' => 'reduce',
        'collect_key' => 'results',
        'expected_count' => 3,
        'count_from_key' => 'count_key',
    ]);
});

it('deserializes from array', function () {
    $node = ReduceNode::fromArray([
        '__synthetic' => 'reduce',
        'collect_key' => 'items',
        'expected_count' => 5,
        'count_from_key' => null,
    ]);

    expect($node->collectKey)->toBe('items');
    expect($node->expectedCount)->toBe(5);
    expect($node->countFromKey)->toBeNull();
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('barrier', new ReduceNode('results', 4))
        ->transition(Workflow::START, 'barrier')
        ->transition('barrier', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('barrier');
    expect($node)->toBeInstanceOf(ReduceNode::class);
    expect($node->collectKey)->toBe('results');
    expect($node->expectedCount)->toBe(4);
});
