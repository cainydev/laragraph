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
use Workbench\App\Workflows\LinearChainWorkflow;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('fires WorkflowStarted on start', function () {
    Event::fake([WorkflowStarted::class]);

    Laragraph::run(LinearChainWorkflow::class);

    Event::assertDispatched(WorkflowStarted::class);
});

it('fires NodeExecuting before node runs', function () {
    Event::fake([NodeExecuting::class]);

    Laragraph::run(LinearChainWorkflow::class);

    Event::assertDispatched(NodeExecuting::class);
});

it('fires NodeCompleted after node runs', function () {
    Event::fake([NodeCompleted::class]);

    Laragraph::run(LinearChainWorkflow::class);

    Event::assertDispatched(NodeCompleted::class);
});

it('fires WorkflowCompleted when workflow finishes', function () {
    Event::fake([WorkflowCompleted::class]);

    Laragraph::run(LinearChainWorkflow::class);

    Event::assertDispatched(WorkflowCompleted::class, fn ($e) => $e->runId > 0);
});

it('fires WorkflowResumed on resume', function () {
    $key = bindTestWorkflow('event-resume-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => ['done' => true]));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
            $this->interruptBefore('step');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Event::fake([WorkflowResumed::class]);

    Laragraph::resume($run->id);

    Event::assertDispatched(WorkflowResumed::class, fn ($e) => $e->runId === $run->id);
});

it('fires WorkflowFailed on abort', function () {
    $key = bindTestWorkflow('event-abort-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
            $this->interruptBefore('step');
        }
    });

    $run = Laragraph::run($key);

    Event::fake([WorkflowFailed::class]);

    Laragraph::abort($run->id);

    Event::assertDispatched(WorkflowFailed::class);
});
