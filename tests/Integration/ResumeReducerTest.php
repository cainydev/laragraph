<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('appends to a list key when resuming with additional state via SmartReducer', function () {
    // SmartReducer appends lists — array_merge would overwrite, exposing the bug
    registerTestWorkflow('reducer-resume-test', Workflow::create()
        ->addNode('read', new FormatNode(fn (array $state) => ['saw' => $state['items'] ?? []]))
        ->transition(Workflow::START, 'read')
        ->transition('read', Workflow::END)
        ->interruptBefore('read'));

    $run = Laragraph::start('reducer-resume-test', ['items' => ['a', 'b']]);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    // Resume with additional items — SmartReducer should append, not overwrite
    Laragraph::resume($run->id, ['items' => ['c']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    // SmartReducer appends: original ['a','b'] + resumed ['c'] = ['a','b','c']
    expect($fresh->state['saw'])->toBe(['a', 'b', 'c']);
});

it('overwrites scalar keys when resuming with additional state', function () {
    registerTestWorkflow('scalar-resume-test', Workflow::create()
        ->addNode('check', new FormatNode(fn (array $state) => ['name' => $state['name'] ?? 'none']))
        ->transition(Workflow::START, 'check')
        ->transition('check', Workflow::END)
        ->interruptBefore('check'));

    $run = Laragraph::start('scalar-resume-test', ['name' => 'original']);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['name' => 'updated']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['name'])->toBe('updated');
});

it('preserves existing state keys not present in additional state', function () {
    registerTestWorkflow('preserve-resume-test', Workflow::create()
        ->addNode('check', new FormatNode(fn (array $state) => [
            'original' => $state['original'] ?? null,
            'extra' => $state['extra'] ?? null,
        ]))
        ->transition(Workflow::START, 'check')
        ->transition('check', Workflow::END)
        ->interruptBefore('check'));

    $run = Laragraph::start('preserve-resume-test', ['original' => 'keep_me']);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['extra' => 'injected']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['original'])->toBe('keep_me');
    expect($fresh->state['extra'])->toBe('injected');
});
