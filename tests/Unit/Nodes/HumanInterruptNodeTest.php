<?php

use Cainy\Laragraph\Events\HumanInterventionRequired;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\HumanInterruptNode;
use Illuminate\Support\Facades\Event;

use function Cainy\Laragraph\Tests\makeContext;

it('throws NodePausedException', function () {
    $node = new HumanInterruptNode();

    expect(fn () => $node->handle(makeContext(), []))->toThrow(NodePausedException::class);
});

it('dispatches HumanInterventionRequired event before throwing', function () {
    Event::fake();

    $node = new HumanInterruptNode();

    try {
        $node->handle(makeContext(runId: 42), []);
    } catch (NodePausedException) {
        // expected
    }

    Event::assertDispatched(HumanInterventionRequired::class, function ($event) {
        return $event->runId === 42;
    });
});
