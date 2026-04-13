<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('interrupt_before pauses before the node runs', function () {
    $key = bindTestWorkflow('ib-pause', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('guarded', new FormatNode(fn () => ['executed' => true]));
            $this->transition(Workflow::START, 'guarded');
            $this->transition('guarded', Workflow::END);
            $this->interruptBefore('guarded');
        }
    });

    $run = Laragraph::run($key);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state)->not->toHaveKey('executed');
});

it('interrupt_before resumes and executes the node', function () {
    $key = bindTestWorkflow('ib-resume', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('guarded', new FormatNode(fn (array $s) => ['result' => $s['input'] ?? 'default']));
            $this->transition(Workflow::START, 'guarded');
            $this->transition('guarded', Workflow::END);
            $this->interruptBefore('guarded');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['input' => 'human-value']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['result'])->toBe('human-value');
});

it('interrupt_after pauses after the node runs', function () {
    $key = bindTestWorkflow('ia-pause', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('producer', new FormatNode(fn () => ['draft' => 'Hello world']));
            $this->addNode('consumer', new FormatNode(fn (array $s) => ['consumed' => $s['draft']]));
            $this->transition(Workflow::START, 'producer');
            $this->transition('producer', 'consumer');
            $this->transition('consumer', Workflow::END);
            $this->interruptAfter('producer');
        }
    });

    $run = Laragraph::run($key);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state['draft'])->toBe('Hello world');
    expect($fresh->state)->not->toHaveKey('consumed');
});

it('interrupt_after resumes and continues to next node', function () {
    $key = bindTestWorkflow('ia-resume', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('producer', new FormatNode(fn () => ['draft' => 'original']));
            $this->addNode('consumer', new FormatNode(fn (array $s) => ['final' => $s['approved_draft'] ?? $s['draft']]));
            $this->transition(Workflow::START, 'producer');
            $this->transition('producer', 'consumer');
            $this->transition('consumer', Workflow::END);
            $this->interruptAfter('producer');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['approved_draft' => 'revised']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['final'])->toBe('revised');
});

it('interrupt_after works in a loop with repeated pauses', function () {
    $counter = new class
    {
        public int $calls = 0;
    };

    $key = bindTestWorkflow('ia-loop', new class($counter) extends Workflow
    {
        public function __construct(private readonly object $counter) {}

        public function definition(): void
        {
            $counter = $this->counter;

            $this->addNode('drafter', new FormatNode(function (array $state) use ($counter) {
                $counter->calls++;

                return ['draft' => "draft v{$counter->calls}", 'draft_num' => $counter->calls];
            }));
            $this->addNode('router', new FormatNode(fn () => []));
            $this->addNode('publish', new FormatNode(fn (array $s) => ['published' => $s['draft']]));
            $this->transition(Workflow::START, 'drafter');
            $this->transition('drafter', 'router');
            $this->branch('router', function (array $state): string {
                return ($state['approve'] ?? false) ? 'publish' : 'drafter';
            }, targets: ['publish', 'drafter']);
            $this->transition('publish', Workflow::END);
            $this->interruptAfter('drafter');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);
    expect($run->fresh()->state['draft'])->toBe('draft v1');

    // Reject — goes back to drafter, pauses again
    Laragraph::resume($run->id, ['approve' => false]);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);
    expect($run->fresh()->state['draft'])->toBe('draft v2');

    // Approve — completes
    Laragraph::resume($run->id, ['approve' => true]);
    expect($run->fresh()->status)->toBe(RunStatus::Completed);
    expect($run->fresh()->state['published'])->toBe('draft v2');
});

it('interrupt_before and interrupt_after can coexist', function () {
    $key = bindTestWorkflow('ib-ia-combo', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('prepare', new FormatNode(fn (array $s) => ['prepared' => $s['config'] ?? 'default']));
            $this->addNode('execute', new FormatNode(fn (array $s) => ['result' => "ran with {$s['prepared']}"]));
            $this->transition(Workflow::START, 'prepare');
            $this->transition('prepare', 'execute');
            $this->transition('execute', Workflow::END);
            $this->interruptBefore('prepare');
            $this->interruptAfter('execute');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);
    expect($run->fresh()->state)->not->toHaveKey('prepared');

    // Resume with config — runs prepare + execute, then pauses after execute
    Laragraph::resume($run->id, ['config' => 'custom']);
    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state['prepared'])->toBe('custom');
    expect($fresh->state['result'])->toBe('ran with custom');

    // Final resume — completes
    Laragraph::resume($run->id);
    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});
