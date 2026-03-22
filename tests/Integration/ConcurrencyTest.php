<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

it('fan-out dispatches multiple pointers and fan-in completes', function () {
    registerTestWorkflow('fanout-test', Workflow::create()
        ->addNode('a', new FormatNode(fn () => ['a_done' => true]))
        ->addNode('b', new FormatNode(fn () => ['b_done' => true]))
        ->addNode('merge', new FormatNode(fn (array $s) => [
            'merged' => ($s['a_done'] ?? false) && ($s['b_done'] ?? false),
        ]))
        ->transition(Workflow::START, 'a')
        ->transition(Workflow::START, 'b')
        ->transition('a', 'merge')
        ->transition('b', 'merge')
        ->transition('merge', Workflow::END));

    $run = Laragraph::start('fanout-test');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['a_done'])->toBeTrue();
    expect($fresh->state['b_done'])->toBeTrue();
});

it('Send objects dispatch with isolated payloads via branch', function () {
    // Use a dispatcher node that fans out via branch edge returning Send objects
    registerTestWorkflow('send-test', Workflow::create()
        ->addNode('dispatcher', new FormatNode(fn () => []))
        ->addNode('worker', new FormatNode(fn (array $state, ?array $payload) => [
            'results' => [($payload['item'] ?? 'none').' processed'],
        ]))
        ->addNode('collector', new FormatNode(fn (array $state) => [
            'report' => implode(', ', $state['results'] ?? []),
        ]))
        ->transition(Workflow::START, 'dispatcher')
        ->branch('dispatcher', fn (array $state) => array_map(
            fn ($item) => new Send('worker', ['item' => $item]),
            $state['items'] ?? [],
        ), targets: ['worker'])
        ->transition('worker', 'collector')
        ->transition('collector', Workflow::END));

    $run = Laragraph::start('send-test', ['items' => ['alpha', 'beta']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['results'])->toContain('alpha processed');
    expect($fresh->state['results'])->toContain('beta processed');
});

it('Send objects work from START via branch', function () {
    registerTestWorkflow('send-from-start', Workflow::create()
        ->addNode('worker', new FormatNode(fn (array $state, ?array $payload) => [
            'results' => [($payload['item'] ?? 'none').' done'],
        ]))
        ->addNode('finish', new FormatNode(fn (array $state) => [
            'summary' => count($state['results'] ?? []).' items',
        ]))
        ->branch(Workflow::START, fn (array $state) => array_map(
            fn ($item) => new Send('worker', ['item' => $item]),
            $state['items'] ?? [],
        ), targets: ['worker'])
        ->transition('worker', 'finish')
        ->transition('finish', Workflow::END));

    $run = Laragraph::start('send-from-start', ['items' => ['x', 'y', 'z']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['results'])->toContain('x done');
    expect($fresh->state['results'])->toContain('y done');
    expect($fresh->state['results'])->toContain('z done');
});
