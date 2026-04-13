<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('appends to a list key when resuming with additional state via SmartReducer', function () {
    $key = bindTestWorkflow('reducer-resume-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('read', new FormatNode(fn (array $state) => ['saw' => $state['items'] ?? []]));
            $this->transition(Workflow::START, 'read');
            $this->transition('read', Workflow::END);
            $this->interruptBefore('read');
        }
    });

    $run = Laragraph::run($key, ['items' => ['a', 'b']]);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['items' => ['c']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['saw'])->toBe(['a', 'b', 'c']);
});

it('overwrites scalar keys when resuming with additional state', function () {
    $key = bindTestWorkflow('scalar-resume-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('check', new FormatNode(fn (array $state) => ['name' => $state['name'] ?? 'none']));
            $this->transition(Workflow::START, 'check');
            $this->transition('check', Workflow::END);
            $this->interruptBefore('check');
        }
    });

    $run = Laragraph::run($key, ['name' => 'original']);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['name' => 'updated']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['name'])->toBe('updated');
});

it('preserves existing state keys not present in additional state', function () {
    $key = bindTestWorkflow('preserve-resume-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('check', new FormatNode(fn (array $state) => [
                'original' => $state['original'] ?? null,
                'extra' => $state['extra'] ?? null,
            ]));
            $this->transition(Workflow::START, 'check');
            $this->transition('check', Workflow::END);
            $this->interruptBefore('check');
        }
    });

    $run = Laragraph::run($key, ['original' => 'keep_me']);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['extra' => 'injected']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['original'])->toBe('keep_me');
    expect($fresh->state['extra'])->toBe('injected');
});
