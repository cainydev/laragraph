<?php

use Cainy\Laragraph\Nodes\BarrierNode;

use function Cainy\Laragraph\Tests\makeContext;

it('always returns empty state mutation', function () {
    $node = new BarrierNode;

    expect($node->handle(makeContext(), []))->toBe([]);
});

it('passes through with populated state unchanged', function () {
    $node = new BarrierNode;

    $state = ['results' => ['a', 'b', 'c'], 'other' => 42];
    expect($node->handle(makeContext(), $state))->toBe([]);
});
