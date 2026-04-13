<?php

use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\GateNode;

use function Cainy\Laragraph\Tests\makeContext;

it('throws NodePausedException', function () {
    $node = new GateNode;

    expect(fn () => $node->handle(makeContext(), []))->toThrow(NodePausedException::class);
});

it('stores the reason in state mutation', function () {
    $node = new GateNode('Manager approval needed');

    try {
        $node->handle(makeContext(), []);
    } catch (NodePausedException $e) {
        expect($e->stateMutation['gate_reason'])->toBe('Manager approval needed');
    }
});
