<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Nodes\BarrierNode;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Nodes\GateNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

/**
 * The "state" column must contain only user-authored keys. Engine plumbing
 * (spawn counters, interrupt marker, gate reason, child-run bookkeeping,
 * error summaries) must live on the "routing" column instead.
 */
function assertCleanState(array $state): void
{
    foreach ($state as $key => $_) {
        // No leaked engine internals with the old __prefix convention.
        expect(str_starts_with((string) $key, '__'))->toBeFalse(
            "Engine key [{$key}] leaked into user state."
        );
    }

    // Keys that used to pollute user state in previous versions.
    expect($state)->not->toHaveKey('gate_reason');
    expect($state)->not->toHaveKey('error');
}

it('fan-out + barrier keeps user state clean and records spawns in routing', function () {
    $key = bindTestWorkflow('routing-clean-fanout', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(fn (array $s, ?array $p) => [
                'results' => [$p['id']],
            ]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tail', new FormatNode(fn () => ['done' => true]));

            $this->branch(Workflow::START, fn () => [
                new Send('worker', ['id' => 1]),
                new Send('worker', ['id' => 2]),
                new Send('worker', ['id' => 3]),
            ], targets: ['worker']);
            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'tail');
            $this->transition('tail', Workflow::END);
        }
    });

    $run = Laragraph::run($key)->fresh();

    expect($run->status)->toBe(RunStatus::Completed);
    assertCleanState($run->state);

    // Routing counters visible and correct.
    expect($run->routing)->toHaveKey('expected_spawns');
    expect($run->routing)->toHaveKey('completed_spawns');
    expect($run->routing['expected_spawns']['worker'])->toBe(3);
    expect($run->routing['completed_spawns']['worker'])->toBe(3);
    // Each worker transitions into barrier — three incoming, one completion.
    expect($run->routing['expected_spawns']['barrier'])->toBe(3);
    expect($run->routing['completed_spawns']['barrier'])->toBe(1);
});

it('keeps user state clean when interrupt_before pauses the run', function () {
    $key = bindTestWorkflow('routing-clean-interrupt-before', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('gate', new FormatNode(fn () => ['gated' => true]));
            $this->transition(Workflow::START, 'gate');
            $this->transition('gate', Workflow::END);
            $this->interruptBefore('gate');
        }
    });

    Laragraph::run($key, ['user_data' => 'x']);
    $run = WorkflowRun::latest()->first();

    expect($run->status)->toBe(RunStatus::Paused);
    expect($run->state)->toBe(['user_data' => 'x']);
    assertCleanState($run->state);

    expect($run->routing)->toHaveKey('interrupt');
    expect($run->routing['interrupt'])->toBe('gate');
});

it('keeps user state clean when GateNode pauses the run', function () {
    $key = bindTestWorkflow('routing-clean-gate', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('approve', new GateNode('Needs manager approval'));
            $this->transition(Workflow::START, 'approve');
            $this->transition('approve', Workflow::END);
        }
    });

    Laragraph::run($key, ['order_id' => 42]);
    $run = WorkflowRun::latest()->first();

    expect($run->status)->toBe(RunStatus::Paused);
    expect($run->state)->toBe(['order_id' => 42]);
    assertCleanState($run->state);

    expect($run->routing['gate_reason'])->toBe('Needs manager approval');
    expect($run->routing['interrupt'])->toBe('approve');
});

it('keeps user state clean when a node fails', function () {
    $key = bindTestWorkflow('routing-clean-failure', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('boom', new FormatNode(function () {
                throw new RuntimeException('kaboom');
            }));
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }
    });

    try {
        Laragraph::run($key, ['context' => 'preserved']);
    } catch (Throwable) {
    }

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Failed);
    expect($run->state)->toBe(['context' => 'preserved']);
    assertCleanState($run->state);

    expect($run->routing)->toHaveKey('error');
    expect($run->routing['error']['node'])->toBe('boom');
    expect($run->routing['error']['class'])->toBe(RuntimeException::class);
});

it('keeps user state clean when a sub-workflow is embedded as a node', function () {
    $childKey = bindTestWorkflow('routing-clean-child', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('inner', new FormatNode(fn () => ['inner_done' => true]));
            $this->transition(Workflow::START, 'inner');
            $this->transition('inner', Workflow::END);
        }
    });

    $parentKey = bindTestWorkflow('routing-clean-parent', new class($childKey) extends Workflow
    {
        public function __construct(private readonly string $childKey) {}

        public function definition(): void
        {
            $this->addNode('sub', app($this->childKey));
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey)->fresh();

    expect($run->status)->toBe(RunStatus::Completed);
    // Parent sees inner_done (child result merged back via diff), nothing else.
    expect($run->state)->toBe(['inner_done' => true]);
    assertCleanState($run->state);
});
