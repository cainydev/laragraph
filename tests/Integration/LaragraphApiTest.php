<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('starts a registered workflow and runs to completion', function () {
    $run = Laragraph::start('linear-chain');

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('starts from blueprint with string node classes', function () {
    // startFromBlueprint serializes to JSON — nodes must be class-strings
    registerTestWorkflow('echo-test', Workflow::create()
        ->addNode('echo', new FormatNode(fn (array $state) => ['echoed' => $state['input'] ?? 'none']))
        ->transition(Workflow::START, 'echo')
        ->transition('echo', Workflow::END));

    $run = Laragraph::start('echo-test', ['input' => 'hello']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['echoed'])->toBe('hello');
});

it('pauses via interrupt_before', function () {
    registerTestWorkflow('pause-test', Workflow::create()
        ->addNode('step', new FormatNode(fn () => ['done' => true]))
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->interruptBefore('step'));

    $run = Laragraph::start('pause-test');

    expect($run->fresh()->status)->toBe(RunStatus::Paused);
});

it('rejects pausing a non-running workflow', function () {
    $run = Laragraph::start('linear-chain');
    expect($run->fresh()->status)->toBe(RunStatus::Completed);

    expect(fn () => Laragraph::pause($run->id))->toThrow(RuntimeException::class, 'not running');
});

it('resumes a paused workflow', function () {
    registerTestWorkflow('resume-test', Workflow::create()
        ->addNode('step', new FormatNode(fn (array $state) => ['result' => $state['input'] ?? 'default']))
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->interruptBefore('step'));

    $run = Laragraph::start('resume-test');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['input' => 'from-human']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['result'])->toBe('from-human');
});

it('rejects resuming a non-paused workflow', function () {
    $run = Laragraph::start('linear-chain');

    expect(fn () => Laragraph::resume($run->id))
        ->toThrow(RuntimeException::class, 'not paused');
});

it('merges additional state on resume', function () {
    registerTestWorkflow('merge-test', Workflow::create()
        ->addNode('check', new FormatNode(fn (array $state) => ['saw_extra' => $state['extra'] ?? false]))
        ->transition(Workflow::START, 'check')
        ->transition('check', Workflow::END)
        ->interruptBefore('check'));

    $run = Laragraph::start('merge-test', ['original' => true]);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['extra' => 'injected']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['saw_extra'])->toBe('injected');
    expect($fresh->state['original'])->toBeTrue();
});

it('aborts a workflow', function () {
    registerTestWorkflow('abort-test', Workflow::create()
        ->addNode('step', new FormatNode(fn () => []))
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->interruptBefore('step'));

    $run = Laragraph::start('abort-test');
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::abort($run->id);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Failed);
    expect($fresh->active_pointers)->toBe([]);
});
