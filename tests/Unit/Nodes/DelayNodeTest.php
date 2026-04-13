<?php

use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Jobs\ResumeWorkflowJob;
use Cainy\Laragraph\Nodes\DelayNode;
use Illuminate\Support\Facades\Queue;

use function Cainy\Laragraph\Tests\makeContext;

it('pauses on first execution, stores a resume timestamp, and enqueues a ResumeWorkflowJob', function () {
    Queue::fake();

    $node = new DelayNode(seconds: 60);

    try {
        $node->handle(makeContext(runId: 42, nodeName: 'wait'), []);
        $this->fail('Expected NodePausedException');
    } catch (NodePausedException $e) {
        expect($e->stateMutation)->toHaveKey('__delay_resume_wait');
        expect($e->stateMutation['__delay_resume_wait'])->toBeGreaterThan(now()->timestamp);
    }

    Queue::assertPushed(ResumeWorkflowJob::class, function (ResumeWorkflowJob $job) {
        return $job->runId === 42 && $job->delay === 60;
    });
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
