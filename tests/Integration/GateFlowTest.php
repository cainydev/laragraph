<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\HumanInterventionRequired;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Nodes\GateNode;
use Illuminate\Support\Facades\Event;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('fires HumanInterventionRequired with the gate reason when GateNode pauses', function () {
    Event::fake([HumanInterventionRequired::class]);

    $key = bindTestWorkflow('gate-event-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('approve', new GateNode('Manager approval required'));
            $this->transition(Workflow::START, 'approve');
            $this->transition('approve', Workflow::END);
        }
    });

    Laragraph::run($key);

    Event::assertDispatched(
        HumanInterventionRequired::class,
        fn (HumanInterventionRequired $e) => $e->nodeName === 'approve'
            && $e->reason === 'Manager approval required'
    );
});

it('resumes cleanly from interruptAfter and runs the downstream node', function () {
    $key = bindTestWorkflow('interrupt-resume-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('draft', new FormatNode(fn () => ['draft' => 'v1']));
            $this->addNode('publish', new FormatNode(fn () => ['published' => true]));
            $this->transition(Workflow::START, 'draft');
            $this->transition('draft', 'publish');
            $this->transition('publish', Workflow::END);
            $this->interruptAfter('draft');
        }
    });

    Laragraph::run($key);

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Paused);
    expect($run->routing['interrupt'])->toBe('draft');
    // Node ran, mutation was applied.
    expect($run->state['draft'])->toBe('v1');

    Laragraph::resume($run->id, ['reviewer' => 'jane']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['draft'])->toBe('v1');
    expect($fresh->state['reviewer'])->toBe('jane');
    expect($fresh->state['published'])->toBeTrue();
    // Interrupt marker cleared on resume.
    expect($fresh->routing)->not->toHaveKey('interrupt');
});

it('preserves routing counters while paused at a gate', function () {
    $key = bindTestWorkflow('gate-routing-counter-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('approve', new GateNode('Review'));
            $this->transition(Workflow::START, 'approve');
            $this->transition('approve', Workflow::END);
        }
    });

    Laragraph::run($key);
    $run = WorkflowRun::latest()->first();

    expect($run->status)->toBe(RunStatus::Paused);
    expect($run->routing['expected_spawns']['approve'])->toBe(1);
    // GateNode never completes (pause), so no completion recorded.
    expect($run->routing['completed_spawns'] ?? [])->not->toHaveKey('approve');
});
