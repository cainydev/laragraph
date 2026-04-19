<?php

use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\GateNode;

use function Cainy\Laragraph\Tests\makeContext;

it('throws NodePausedException', function () {
    $node = new GateNode;

    expect(fn () => $node->handle(makeContext(), []))->toThrow(NodePausedException::class);
});

it('surfaces the reason on the paused exception', function () {
    $node = new GateNode('Manager approval needed');

    try {
        $node->handle(makeContext(), []);
    } catch (NodePausedException $e) {
        expect($e->gateReason)->toBe('Manager approval needed');
    }
});
