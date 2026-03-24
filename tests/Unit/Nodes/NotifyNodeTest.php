<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Nodes\NotifyNode;
use Illuminate\Support\Facades\Event;

use function Cainy\Laragraph\Tests\makeContext;

// Minimal stub event for testing
class StubNotifyEvent
{
    public function __construct(public readonly mixed $arg1 = null, public readonly mixed $arg2 = null) {}
}

it('dispatches the event with state values as arguments', function () {
    Event::fake([StubNotifyEvent::class]);

    $node = new NotifyNode(StubNotifyEvent::class, ['user_id', 'role']);
    $node->handle(makeContext(), ['user_id' => 42, 'role' => 'admin']);

    Event::assertDispatched(StubNotifyEvent::class, function (StubNotifyEvent $e) {
        return $e->arg1 === 42 && $e->arg2 === 'admin';
    });
});

it('passes null for missing state keys', function () {
    Event::fake([StubNotifyEvent::class]);

    $node = new NotifyNode(StubNotifyEvent::class, ['missing_key']);
    $node->handle(makeContext(), []);

    Event::assertDispatched(StubNotifyEvent::class, fn ($e) => $e->arg1 === null);
});

it('dispatches with no arguments when dataKeys is empty', function () {
    Event::fake([StubNotifyEvent::class]);

    $node = new NotifyNode(StubNotifyEvent::class);
    $result = $node->handle(makeContext(), []);

    Event::assertDispatched(StubNotifyEvent::class);
    expect($result)->toBe([]);
});

it('serializes to array', function () {
    $node = new NotifyNode(StubNotifyEvent::class, ['user_id']);

    expect($node->toArray())->toBe([
        '__synthetic' => 'notify',
        'event_class' => StubNotifyEvent::class,
        'data_keys' => ['user_id'],
    ]);
});

it('deserializes from array', function () {
    $node = NotifyNode::fromArray([
        '__synthetic' => 'notify',
        'event_class' => StubNotifyEvent::class,
        'data_keys' => ['a', 'b'],
    ]);

    expect($node->eventClass)->toBe(StubNotifyEvent::class);
    expect($node->dataKeys)->toBe(['a', 'b']);
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('notify', new NotifyNode(StubNotifyEvent::class, ['run_id']))
        ->transition(Workflow::START, 'notify')
        ->transition('notify', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('notify');
    expect($node)->toBeInstanceOf(NotifyNode::class);
    expect($node->eventClass)->toBe(StubNotifyEvent::class);
    expect($node->dataKeys)->toBe(['run_id']);
});
