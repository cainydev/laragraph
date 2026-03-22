<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('rejects compile when no edges from START', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', new FormatNode(fn () => []))
        ->transition('a', Workflow::END)
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'at least one edge from __START__');
});

it('rejects compile when edge targets START', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', new FormatNode(fn () => []))
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::START)
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'Edges to __START__');
});

it('rejects compile when edge originates from END', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', new FormatNode(fn () => []))
        ->addNode('b', new FormatNode(fn () => []))
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->transition(Workflow::END, 'b')
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'Edges from __END__');
});

it('runs a minimal START to END workflow', function () {
    registerTestWorkflow('minimal', Workflow::create()
        ->addNode('noop', new FormatNode(fn () => ['ran' => true]))
        ->transition(Workflow::START, 'noop')
        ->transition('noop', Workflow::END));

    $run = Laragraph::start('minimal');

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
    expect($run->fresh()->state['ran'])->toBeTrue();
});
