<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('fan-out dispatches multiple pointers and fan-in completes', function () {
    $key = bindTestWorkflow('fanout-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('a', new FormatNode(fn () => ['a_done' => true]));
            $this->addNode('b', new FormatNode(fn () => ['b_done' => true]));
            $this->addNode('merge', new FormatNode(fn (array $s) => [
                'merged' => ($s['a_done'] ?? false) && ($s['b_done'] ?? false),
            ]));
            $this->transition(Workflow::START, 'a');
            $this->transition(Workflow::START, 'b');
            $this->transition('a', 'merge');
            $this->transition('b', 'merge');
            $this->transition('merge', Workflow::END);
        }
    });

    $run = Laragraph::run($key);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['a_done'])->toBeTrue();
    expect($fresh->state['b_done'])->toBeTrue();
});

it('Send objects dispatch with isolated payloads via branch', function () {
    $key = bindTestWorkflow('send-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('dispatcher', new FormatNode(fn () => []));
            $this->addNode('worker', new FormatNode(fn (array $state, ?array $payload) => [
                'results' => [($payload['item'] ?? 'none').' processed'],
            ]));
            $this->addNode('collector', new FormatNode(fn (array $state) => [
                'report' => implode(', ', $state['results'] ?? []),
            ]));
            $this->transition(Workflow::START, 'dispatcher');
            $this->branch('dispatcher', fn (array $state) => array_map(
                fn ($item) => new Send('worker', ['item' => $item]),
                $state['items'] ?? [],
            ), targets: ['worker']);
            $this->transition('worker', 'collector');
            $this->transition('collector', Workflow::END);
        }
    });

    $run = Laragraph::run($key, ['items' => ['alpha', 'beta']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['results'])->toContain('alpha processed');
    expect($fresh->state['results'])->toContain('beta processed');
});

it('Send objects work from START via branch', function () {
    $key = bindTestWorkflow('send-from-start', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(fn (array $state, ?array $payload) => [
                'results' => [($payload['item'] ?? 'none').' done'],
            ]));
            $this->addNode('finish', new FormatNode(fn (array $state) => [
                'summary' => count($state['results'] ?? []).' items',
            ]));
            $this->branch(Workflow::START, fn (array $state) => array_map(
                fn ($item) => new Send('worker', ['item' => $item]),
                $state['items'] ?? [],
            ), targets: ['worker']);
            $this->transition('worker', 'finish');
            $this->transition('finish', Workflow::END);
        }
    });

    $run = Laragraph::run($key, ['items' => ['x', 'y', 'z']]);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['results'])->toContain('x done');
    expect($fresh->state['results'])->toContain('y done');
    expect($fresh->state['results'])->toContain('z done');
});
