<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Nodes\DelayNode;

use function Cainy\Laragraph\Tests\makeContext;

it('pauses on first execution and stores a resume timestamp', function () {
    $node = new DelayNode(seconds: 60);

    try {
        $node->handle(makeContext(nodeName: 'wait'), []);
        $this->fail('Expected NodePausedException');
    } catch (NodePausedException $e) {
        expect($e->stateMutation)->toHaveKey('__delay_resume_wait');
        expect($e->stateMutation['__delay_resume_wait'])->toBeGreaterThan(now()->timestamp);
    }
});

it('pauses again when resume time has not passed', function () {
    $node = new DelayNode(seconds: 3600);

    $state = ['__delay_resume_wait' => now()->addHour()->timestamp];
    expect(fn () => $node->handle(makeContext(nodeName: 'wait'), $state))->toThrow(NodePausedException::class);
});

it('passes through and clears marker when delay has elapsed', function () {
    $node = new DelayNode(seconds: 1);

    $state = ['__delay_resume_wait' => now()->subSeconds(5)->timestamp]; // already past
    $result = $node->handle(makeContext(nodeName: 'wait'), $state);

    expect($result)->toBe(['__delay_resume_wait' => null]);
});

it('serializes to array', function () {
    $node = new DelayNode(120);

    expect($node->toArray())->toBe([
        '__synthetic' => 'delay',
        'seconds' => 120,
    ]);
});

it('deserializes from array', function () {
    $node = DelayNode::fromArray(['__synthetic' => 'delay', 'seconds' => 300]);

    expect($node->seconds)->toBe(300);
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('pause', new DelayNode(90))
        ->transition(Workflow::START, 'pause')
        ->transition('pause', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('pause');
    expect($node)->toBeInstanceOf(DelayNode::class);
    expect($node->seconds)->toBe(90);
});
