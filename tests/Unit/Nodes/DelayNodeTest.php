<?php

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

    $state = ['__delay_resume_wait' => now()->subSeconds(5)->timestamp];
    $result = $node->handle(makeContext(nodeName: 'wait'), $state);

    expect($result)->toBe(['__delay_resume_wait' => null]);
});
