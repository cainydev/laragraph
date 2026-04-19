<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasTags;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Nodes\BarrierNode;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

/**
 * These tests stress the fan-out / fan-in machinery under conditions the
 * original feedback identified as production-feeling: many items, nested
 * pipelines, and chains where the barrier sits between deeper work.
 */
it('handles fan-out with 25 Send items through a barrier exactly once', function () {
    $key = bindTestWorkflow('stress-fanout-25', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(fn (array $s, ?array $p) => [
                'results' => [$p['id']],
            ]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tail', new FormatNode(fn (array $s) => [
                'fired' => ($s['fired'] ?? 0) + 1,
                'count' => count($s['results'] ?? []),
            ]));

            $this->branch(Workflow::START, fn (array $s) => collect($s['ids'])->map(
                fn ($id) => new Send('worker', ['id' => $id])
            )->all(), ['worker']);

            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'tail');
            $this->transition('tail', Workflow::END);

            // 25 items will blow past the old recursion_limit=25 default but
            // survives the new 100. Keep explicit to pin the behaviour.
            $this->withRecursionLimit(200);
        }
    });

    $ids = range(1, 25);
    $run = Laragraph::run($key, ['ids' => $ids]);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->active_pointers)->toBeEmpty();
    // Every worker contributed exactly once.
    expect($fresh->state['results'])->toHaveCount(25);
    expect(array_values($fresh->state['results']))->toEqualCanonicalizing($ids);
    // Barrier fired downstream exactly once.
    expect($fresh->state['fired'])->toBe(1);
    expect($fresh->state['count'])->toBe(25);
});

it('survives nested fan-out: parent Send → child workflow that itself fans out + barrier', function () {
    // Child workflow: per-item, it fans out N stages, barriers, then tallies.
    class NestedFanoutChild extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('stage', new FormatNode(fn (array $s, ?array $p) => [
                'stages' => [$p['stage_id']],
            ]));
            $this->addNode('inner_barrier', new BarrierNode);
            $this->addNode('aggregate', new FormatNode(fn (array $s) => [
                'aggregated_for_id' => $s['id'],
                'stage_count' => count($s['stages'] ?? []),
            ]));

            $this->branch(Workflow::START, fn (array $s) => [
                new Send('stage', ['stage_id' => 'a-'.$s['id']]),
                new Send('stage', ['stage_id' => 'b-'.$s['id']]),
                new Send('stage', ['stage_id' => 'c-'.$s['id']]),
            ], ['stage']);

            $this->transition('stage', 'inner_barrier');
            $this->transition('inner_barrier', 'aggregate');
            $this->transition('aggregate', Workflow::END);

            $this->withRecursionLimit(200);
        }
    }

    $parentKey = bindTestWorkflow('stress-nested-fanout', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('per_item', app(NestedFanoutChild::class));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tail', new FormatNode(fn (array $s) => [
                'fired' => ($s['fired'] ?? 0) + 1,
            ]));

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('per_item', ['id' => 1]),
                Send::toWorkflow('per_item', ['id' => 2]),
                Send::toWorkflow('per_item', ['id' => 3]),
            ], ['per_item']);

            $this->transition('per_item', 'barrier');
            $this->transition('barrier', 'tail');
            $this->transition('tail', Workflow::END);

            $this->withRecursionLimit(200);
        }
    });

    $run = Laragraph::run($parentKey);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->active_pointers)->toBeEmpty();
    expect($fresh->state['fired'])->toBe(1);

    // Three children, each completed with 3 stages.
    $children = WorkflowRun::where('parent_run_id', $run->id)->get();
    expect($children)->toHaveCount(3);
    foreach ($children as $c) {
        expect($c->status)->toBe(RunStatus::Completed);
        expect($c->state['stage_count'])->toBe(3);
    }
});

it('fan-in downstream runs exactly once even when a worker has HasTags (success row)', function () {
    // Regression guard: success rows on workflow_node_executions must not
    // interfere with spawn/completion bookkeeping.
    class TaggedWorker implements HasTags, Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['results' => [$context->payload('id')]];
        }

        public function tags(): array
        {
            return ['cost_usd' => 0.001];
        }
    }

    $key = bindTestWorkflow('tagged-worker-barrier', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new TaggedWorker);
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tail', new FormatNode(fn (array $s) => [
                'fired' => ($s['fired'] ?? 0) + 1,
            ]));

            $this->branch(Workflow::START, fn () => [
                new Send('worker', ['id' => 'a']),
                new Send('worker', ['id' => 'b']),
                new Send('worker', ['id' => 'c']),
                new Send('worker', ['id' => 'd']),
            ], ['worker']);

            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'tail');
            $this->transition('tail', Workflow::END);
        }
    });

    $run = Laragraph::run($key)->fresh();

    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->state['fired'])->toBe(1);
    expect($run->state['results'])->toHaveCount(4);
    expect($run->active_pointers)->toBeEmpty();

    // Routing counters.
    expect($run->routing['expected_spawns']['worker'])->toBe(4);
    expect($run->routing['completed_spawns']['worker'])->toBe(4);
    // Barrier has 4 incoming (one per worker) and completes once.
    expect($run->routing['expected_spawns']['barrier'])->toBe(4);
    expect($run->routing['completed_spawns']['barrier'])->toBe(1);
});

it('cascades failure when ONE of many Send-dispatched children fails', function () {
    // Cascade should trigger on the first failing child; siblings may or may
    // not have completed, but the parent must end up Failed either way.
    class PartiallyFailingChildNode implements Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            if (($state['id'] ?? 0) === 2) {
                throw new RuntimeException('child #2 exploded');
            }

            return ['ok_ids' => [$state['id']]];
        }
    }

    class PartiallyFailingChildWorkflow extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('maybe_boom', new PartiallyFailingChildNode);
            $this->transition(Workflow::START, 'maybe_boom');
            $this->transition('maybe_boom', Workflow::END);
        }
    }

    $parentKey = bindTestWorkflow('stress-partial-fail-cascade', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('per_item', app(PartiallyFailingChildWorkflow::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('per_item', ['id' => 1]),
                Send::toWorkflow('per_item', ['id' => 2]),
                Send::toWorkflow('per_item', ['id' => 3]),
            ], ['per_item']);

            $this->transition('per_item', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    try {
        Laragraph::run($parentKey);
    } catch (Throwable) {
    }

    $parent = WorkflowRun::where('key', $parentKey)->latest('id')->first();
    expect($parent->fresh()->status)->toBe(RunStatus::Failed);
    expect($parent->fresh()->routing['error']['from_child'])->toBeTrue();
    expect($parent->fresh()->routing['error']['message'])->toContain('child #2 exploded');
});

it('fan-out completes even when payloads carry identical data', function () {
    // Guards against a bug where duplicate payloads could be dedup'd or
    // merged — each Send must produce its own worker execution.
    $key = bindTestWorkflow('duplicate-payload-fanout', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('worker', new FormatNode(fn (array $s, ?array $p) => [
                'count' => ($s['count'] ?? 0) + 1,
            ]));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tail', new FormatNode(fn () => []));

            $this->branch(Workflow::START, fn () => [
                new Send('worker', ['id' => 'same']),
                new Send('worker', ['id' => 'same']),
                new Send('worker', ['id' => 'same']),
            ], ['worker']);

            $this->transition('worker', 'barrier');
            $this->transition('barrier', 'tail');
            $this->transition('tail', Workflow::END);
        }
    });

    $run = Laragraph::run($key)->fresh();

    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->state['count'])->toBe(3);
});
