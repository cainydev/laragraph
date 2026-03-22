<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('interrupt_before pauses before the node runs', function () {
    registerTestWorkflow('ib-pause', Workflow::create()
        ->addNode('guarded', new FormatNode(fn () => ['executed' => true]))
        ->transition(Workflow::START, 'guarded')
        ->transition('guarded', Workflow::END)
        ->interruptBefore('guarded'));

    $run = Laragraph::start('ib-pause');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state)->not->toHaveKey('executed');
});

it('interrupt_before resumes and executes the node', function () {
    registerTestWorkflow('ib-resume', Workflow::create()
        ->addNode('guarded', new FormatNode(fn (array $s) => ['result' => $s['input'] ?? 'default']))
        ->transition(Workflow::START, 'guarded')
        ->transition('guarded', Workflow::END)
        ->interruptBefore('guarded'));

    $run = Laragraph::start('ib-resume');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['input' => 'human-value']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['result'])->toBe('human-value');
});

it('interrupt_after pauses after the node runs', function () {
    registerTestWorkflow('ia-pause', Workflow::create()
        ->addNode('producer', new FormatNode(fn () => ['draft' => 'Hello world']))
        ->addNode('consumer', new FormatNode(fn (array $s) => ['consumed' => $s['draft']]))
        ->transition(Workflow::START, 'producer')
        ->transition('producer', 'consumer')
        ->transition('consumer', Workflow::END)
        ->interruptAfter('producer'));

    $run = Laragraph::start('ia-pause');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Paused);
    expect($fresh->state['draft'])->toBe('Hello world');
    expect($fresh->state)->not->toHaveKey('consumed');
});

it('interrupt_after resumes and continues to next node', function () {
    registerTestWorkflow('ia-resume', Workflow::create()
        ->addNode('producer', new FormatNode(fn () => ['draft' => 'original']))
        ->addNode('consumer', new FormatNode(fn (array $s) => ['final' => $s['approved_draft'] ?? $s['draft']]))
        ->transition(Workflow::START, 'producer')
        ->transition('producer', 'consumer')
        ->transition('consumer', Workflow::END)
        ->interruptAfter('producer'));

    $run = Laragraph::start('ia-resume');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['approved_draft' => 'revised']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['final'])->toBe('revised');
});

it('interrupt_after works in a loop with repeated pauses', function () {
    $counter = new class {
        public int $calls = 0;
    };

    registerTestWorkflow('ia-loop', Workflow::create()
        ->addNode('drafter', new FormatNode(function (array $state) use ($counter) {
            $counter->calls++;

            return ['draft' => "draft v{$counter->calls}", 'draft_num' => $counter->calls];
        }))
        ->addNode('router', new FormatNode(fn () => []))
        ->addNode('publish', new FormatNode(fn (array $s) => ['published' => $s['draft']]))
        ->transition(Workflow::START, 'drafter')
        ->transition('drafter', 'router')
        ->branch('router', function (array $state): string {
            return ($state['approve'] ?? false) ? 'publish' : 'drafter';
        }, targets: ['publish', 'drafter'])
        ->transition('publish', Workflow::END)
        ->interruptAfter('drafter'));

    $run = Laragraph::start('ia-loop');
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
    registerTestWorkflow('ib-ia-combo', Workflow::create()
        ->addNode('prepare', new FormatNode(fn (array $s) => ['prepared' => $s['config'] ?? 'default']))
        ->addNode('execute', new FormatNode(fn (array $s) => ['result' => "ran with {$s['prepared']}"]))
        ->transition(Workflow::START, 'prepare')
        ->transition('prepare', 'execute')
        ->transition('execute', Workflow::END)
        ->interruptBefore('prepare')
        ->interruptAfter('execute'));

    $run = Laragraph::start('ib-ia-combo');
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
