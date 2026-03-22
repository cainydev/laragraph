<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\NodeCompleted;
use Cainy\Laragraph\Events\NodeExecuting;
use Cainy\Laragraph\Events\WorkflowCompleted;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Events\WorkflowResumed;
use Cainy\Laragraph\Events\WorkflowStarted;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;
use Illuminate\Support\Facades\Event;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('fires WorkflowStarted on start', function () {
    Event::fake([WorkflowStarted::class]);

    Laragraph::start('linear-chain');

    Event::assertDispatched(WorkflowStarted::class);
});

it('fires NodeExecuting before node runs', function () {
    Event::fake([NodeExecuting::class]);

    Laragraph::start('linear-chain');

    Event::assertDispatched(NodeExecuting::class);
});

it('fires NodeCompleted after node runs', function () {
    Event::fake([NodeCompleted::class]);

    Laragraph::start('linear-chain');

    Event::assertDispatched(NodeCompleted::class);
});

it('fires WorkflowCompleted when workflow finishes', function () {
    Event::fake([WorkflowCompleted::class]);

    Laragraph::start('linear-chain');

    Event::assertDispatched(WorkflowCompleted::class, fn ($e) => $e->runId > 0);
});

it('fires WorkflowResumed on resume', function () {
    registerTestWorkflow('event-resume-test', Workflow::create()
        ->addNode('step', new FormatNode(fn () => ['done' => true]))
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->interruptBefore('step'));

    $run = Laragraph::start('event-resume-test');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Event::fake([WorkflowResumed::class]);

    Laragraph::resume($run->id);

    Event::assertDispatched(WorkflowResumed::class, fn ($e) => $e->runId === $run->id);
});

it('fires WorkflowFailed on abort', function () {
    registerTestWorkflow('event-abort-test', Workflow::create()
        ->addNode('step', new FormatNode(fn () => []))
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->interruptBefore('step'));

    $run = Laragraph::start('event-abort-test');

    Event::fake([WorkflowFailed::class]);

    Laragraph::abort($run->id);

    Event::assertDispatched(WorkflowFailed::class);
});
