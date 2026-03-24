<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\GateNode;

use function Cainy\Laragraph\Tests\makeContext;

it('throws NodePausedException', function () {
    $node = new GateNode;

    expect(fn () => $node->handle(makeContext(), []))->toThrow(NodePausedException::class);
});

it('stores gate_reason in state mutation', function () {
    $node = new GateNode('Manager approval required');

    try {
        $node->handle(makeContext(), []);
    } catch (NodePausedException $e) {
        expect($e->stateMutation['gate_reason'])->toBe('Manager approval required');
    }
});

it('uses default reason when none given', function () {
    $node = new GateNode;

    try {
        $node->handle(makeContext(), []);
    } catch (NodePausedException $e) {
        expect($e->stateMutation['gate_reason'])->toBe('Approval required');
    }
});

it('serializes to array', function () {
    $node = new GateNode('Review required');
    $array = $node->toArray();

    expect($array)->toBe([
        '__synthetic' => 'gate',
        'reason' => 'Review required',
    ]);
});

it('deserializes from array', function () {
    $restored = GateNode::fromArray(['__synthetic' => 'gate', 'reason' => 'Custom reason']);

    expect($restored->reason)->toBe('Custom reason');
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('gate', new GateNode('Needs sign-off'))
        ->transition(Workflow::START, 'gate')
        ->transition('gate', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('gate');
    expect($node)->toBeInstanceOf(GateNode::class);
    expect($node->reason)->toBe('Needs sign-off');
});
