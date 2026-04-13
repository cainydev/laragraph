<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\BarrierNode;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('fan-out dispatches multiple pointers and fan-in completes', function () {
    $key = bindTestWorkflow('fanout-test', new class extends Workflow
    {
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
    $key = bindTestWorkflow('send-test', new class extends Workflow
    {
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

it('BarrierNode barrier with Send fan-out fires downstream exactly once', function () {
    $key = bindTestWorkflow('reduce-send-fan-in', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(fn (array $state, ?array $payload) => [
                'items' => [($payload['id'] ?? 'x')],
            ]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('downstream', new FormatNode(fn (array $state) => [
                'fired' => ($state['fired'] ?? 0) + 1,
            ]));
            $this->branch(Workflow::START, fn () => [
                new Send('worker', ['id' => 'a']),
                new Send('worker', ['id' => 'b']),
                new Send('worker', ['id' => 'c']),
            ], targets: ['worker']);
            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'downstream');
            $this->transition('downstream', Workflow::END);
        }
    });

    $run = Laragraph::run($key);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['items'])->toHaveCount(3);
    expect($fresh->state['fired'])->toBe(1);
    expect($fresh->active_pointers)->toBeEmpty();
});

it('BarrierNode barrier with transition fan-out fires downstream exactly once', function () {
    $key = bindTestWorkflow('reduce-transition-fan-in', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', new FormatNode(fn () => ['items' => ['a']]));
            $this->addNode('b', new FormatNode(fn () => ['items' => ['b']]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('counter', new FormatNode(fn (array $state) => [
                'count' => ($state['count'] ?? 0) + 1,
            ]));
            $this->transition(Workflow::START, 'a');
            $this->transition(Workflow::START, 'b');
            $this->transition('a', 'barrier');
            $this->transition('b', 'barrier');
            $this->transition('barrier', 'counter');
            $this->transition('counter', Workflow::END);
        }
    });

    $run = Laragraph::run($key);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['count'])->toBe(1);
    expect($fresh->active_pointers)->toBeEmpty();
});

it('waits for asymmetric branches before passing barrier', function () {
    $key = bindTestWorkflow('asymmetric-barrier', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('fast_worker', new FormatNode(fn () => ['fast' => true]));
            $this->addNode('slow_step_1', new FormatNode(fn () => ['s1' => true]));
            $this->addNode('slow_step_2', new FormatNode(fn () => ['s2' => true]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('end_guard', new FormatNode(fn ($s) => ['fired' => ($s['fired'] ?? 0) + 1]));

            $this->transition(Workflow::START, 'fast_worker');
            $this->transition(Workflow::START, 'slow_step_1');
            $this->transition('fast_worker', 'barrier');
            $this->transition('slow_step_1', 'slow_step_2');
            $this->transition('slow_step_2', 'barrier');
            $this->transition('barrier', 'end_guard');
            $this->transition('end_guard', Workflow::END);
        }
    });

    $run = Laragraph::run($key);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['fired'])->toBe(1);
    expect($fresh->state)->toHaveKeys(['fast', 's1', 's2']);
    expect($fresh->active_pointers)->toBeEmpty();
});

it('does not double-count completions when a worker node is retried on a fresh run', function () {
    // Simulates the error-recovery pattern: first run fails, second run (with corrected
    // state) should still produce exactly one barrier pass and one downstream fire.
    $key = bindTestWorkflow('retry-barrier', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(function (array $state) {
                if (! ($state['ok'] ?? false)) {
                    throw new RuntimeException('Simulated failure');
                }

                return ['finished' => true];
            }));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('end_guard', new FormatNode(fn ($s) => ['fired' => ($s['fired'] ?? 0) + 1]));

            $this->transition(Workflow::START, 'worker');
            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'end_guard');
            $this->transition('end_guard', Workflow::END);
        }
    });

    // First attempt fails — exception propagates on sync queue.
    try {
        Laragraph::run($key, ['ok' => false]);
    } catch (Throwable) {
    }

    // Second run with corrected state should complete cleanly.
    $run2 = Laragraph::run($key, ['ok' => true]);
    $fresh = $run2->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['fired'])->toBe(1);
    expect($fresh->active_pointers)->toBeEmpty();
});

it('safely handles two sequential fan-out/fan-in stages', function () {
    $key = bindTestWorkflow('sequential-barriers', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker_1', new FormatNode(fn () => []));
            $this->addNode('barrier_1', new BarrierNode);
            $this->addNode('worker_2', new FormatNode(fn () => []));
            $this->addNode('barrier_2', new BarrierNode);
            $this->addNode('end_guard', new FormatNode(fn ($s) => ['fired' => ($s['fired'] ?? 0) + 1]));

            $this->branch(Workflow::START, fn () => [
                new Send('worker_1', ['id' => 1]),
                new Send('worker_1', ['id' => 2]),
            ], targets: ['worker_1']);
            $this->transition('worker_1', 'barrier_1');

            $this->branch('barrier_1', fn () => [
                new Send('worker_2', ['id' => 1]),
                new Send('worker_2', ['id' => 2]),
            ], targets: ['worker_2']);
            $this->transition('worker_2', 'barrier_2');

            $this->transition('barrier_2', 'end_guard');
            $this->transition('end_guard', Workflow::END);
        }
    });

    $run = Laragraph::run($key);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['fired'])->toBe(1);
    expect($fresh->active_pointers)->toBeEmpty();
});

it('Send objects work from START via branch', function () {
    $key = bindTestWorkflow('send-from-start', new class extends Workflow
    {
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
