<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\WorkflowResumed;
use Cainy\Laragraph\Facades\Laragraph;
use Illuminate\Support\Facades\Event;
use Workbench\App\Nodes\LinearNodeA;
use Workbench\App\Nodes\LinearNodeB;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('fires WorkflowResumed on the parent when a child workflow completes', function () {
    // Child workflow as a proper Workflow subclass so it can be resolved by class name
    $childKey = bindTestWorkflow('child-workflow', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('child_step', LinearNodeA::class);
            $this->transition(Workflow::START, 'child_step');
            $this->transition('child_step', Workflow::END);
        }
    });

    $parentKey = bindTestWorkflow('parent-child-event-test', new class($childKey) extends Workflow
    {
        public function __construct(private readonly string $childKey) {}

        public function definition(): void
        {
            $this->addNode('sub', app($this->childKey));
            $this->addNode('after', LinearNodeB::class);
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', 'after');
            $this->transition('after', Workflow::END);
        }
    });

    Event::fake([WorkflowResumed::class]);

    $run = Laragraph::run($parentKey);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);

    Event::assertDispatched(WorkflowResumed::class, fn ($e) => $e->runId === $run->id);
});
