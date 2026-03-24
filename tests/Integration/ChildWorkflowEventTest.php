<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\WorkflowResumed;
use Cainy\Laragraph\Facades\Laragraph;
use Illuminate\Support\Facades\Event;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('fires WorkflowResumed on the parent when a child workflow completes', function () {
    // Use workbench node class-strings so the snapshot can be hydrated from the container
    $child = Workflow::create()
        ->addNode('child_step', Workbench\App\Nodes\LinearNodeA::class)
        ->transition(Workflow::START, 'child_step')
        ->transition('child_step', Workflow::END)
        ->compile();

    registerTestWorkflow('parent-child-event-test', Workflow::create()
        ->addNode('sub', $child)
        ->addNode('after', Workbench\App\Nodes\LinearNodeB::class)
        ->transition(Workflow::START, 'sub')
        ->transition('sub', 'after')
        ->transition('after', Workflow::END));

    Event::fake([WorkflowResumed::class]);

    $run = Laragraph::start('parent-child-event-test');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);

    // WorkflowResumed must fire for the parent run ID when the child workflow completes
    Event::assertDispatched(WorkflowResumed::class, fn ($e) => $e->runId === $run->id);
});
