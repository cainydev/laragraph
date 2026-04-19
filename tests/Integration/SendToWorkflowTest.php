<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Nodes\BarrierNode;
use Cainy\Laragraph\Nodes\FormatNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

class EnrichNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['enriched' => ($state['id'] ?? 0) * 10];
    }
}

class QualifyNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['qualified' => ($state['enriched'] ?? 0) + 1];
    }
}

class PerItemPipeline extends Workflow
{
    public function definition(): void
    {
        $this->addNode('enrich', new EnrichNode);
        $this->addNode('qualify', new QualifyNode);
        $this->transition(Workflow::START, 'enrich');
        $this->transition('enrich', 'qualify');
        $this->transition('qualify', Workflow::END);
    }
}

class AlternatePerItemPipeline extends Workflow
{
    public function definition(): void
    {
        $this->addNode('enrich', new EnrichNode);
        $this->transition(Workflow::START, 'enrich');
        $this->transition('enrich', Workflow::END);
    }
}

class SendPipelineFailingNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        throw new RuntimeException('Per-item child failed for id '.($state['id'] ?? '?'));
    }
}

class SendPipelineFailingChild extends Workflow
{
    public function definition(): void
    {
        $this->addNode('boom', new SendPipelineFailingNode);
        $this->transition(Workflow::START, 'boom');
        $this->transition('boom', Workflow::END);
    }
}

it('Send::toWorkflow seeds a per-item sub-workflow with the payload as initial state', function () {
    $parentKey = bindTestWorkflow('send-to-workflow-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('pipeline', app(PerItemPipeline::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn (array $state) => collect($state['ids'])->map(
                fn (int $id) => Send::toWorkflow('pipeline', ['id' => $id])
            )->all(), ['pipeline']);

            $this->transition('pipeline', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey, ['ids' => [1, 2, 3]]);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);

    $children = WorkflowRun::where('parent_run_id', $run->id)->get();
    expect($children)->toHaveCount(3);

    foreach ($children as $child) {
        $id = $child->state['id'];
        expect($child->state['enriched'])->toBe($id * 10);
        expect($child->state['qualified'])->toBe(($id * 10) + 1);
    }
});

it('rejects Send::toWorkflow to START or END sentinels', function () {
    expect(fn () => Send::toWorkflow(Workflow::START, []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => Send::toWorkflow(Workflow::END, []))
        ->toThrow(InvalidArgumentException::class);
});

it('does not leak parent state into Send-dispatched child workflows', function () {
    $parentKey = bindTestWorkflow('send-to-workflow-isolation-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('pipeline', app(PerItemPipeline::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('pipeline', ['id' => 7]),
            ], ['pipeline']);

            $this->transition('pipeline', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey, [
        'ids' => [1, 2, 3],
        'secret' => 'do-not-leak',
    ]);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);

    /** @var WorkflowRun $child */
    $child = WorkflowRun::where('parent_run_id', $run->id)->first();
    expect($child)->not->toBeNull();

    // Child's initial + final state carries ONLY the Send payload, never parent keys.
    expect($child->state)->toHaveKey('id');
    expect($child->state)->not->toHaveKey('ids');
    expect($child->state)->not->toHaveKey('secret');
});

it('fan-out to the same sub-workflow node then barrier merges each child result', function () {
    $parentKey = bindTestWorkflow('send-to-workflow-barrier-merge', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('pipeline', app(PerItemPipeline::class));
            $this->addNode('barrier', new BarrierNode);
            $this->addNode('tally', new FormatNode(fn (array $s) => [
                'total_qualified' => $s['qualified'] ?? 0,
            ]));

            $this->branch(Workflow::START, fn (array $s) => collect($s['ids'])->map(
                fn ($id) => Send::toWorkflow('pipeline', ['id' => $id])
            )->all(), ['pipeline']);

            $this->transition('pipeline', 'barrier');
            $this->transition('barrier', 'tally');
            $this->transition('tally', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey, ['ids' => [1, 2, 3]]);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->active_pointers)->toBeEmpty();
    // All three children completed.
    expect(WorkflowRun::where('parent_run_id', $run->id)->count())->toBe(3);
    // Barrier fired exactly once: tally ran once.
    expect($fresh->state)->toHaveKey('total_qualified');
});

it('propagates failure from a Send-dispatched child when cascade is on (default)', function () {
    $parentKey = bindTestWorkflow('send-to-workflow-cascade', new class extends Workflow
    {
        public function definition(): void
        {
            // SendPipelineFailingChild is defined below (cascade default).
            $this->addNode('pipeline', app(SendPipelineFailingChild::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('pipeline', ['id' => 1]),
                Send::toWorkflow('pipeline', ['id' => 2]),
            ], ['pipeline']);

            $this->transition('pipeline', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    try {
        Laragraph::run($parentKey);
    } catch (Throwable) {
        // sync queue re-throws
    }

    $parent = WorkflowRun::where('key', $parentKey)->latest('id')->first();
    expect($parent->fresh()->status)->toBe(RunStatus::Failed);
    expect($parent->fresh()->routing['error']['from_child'])->toBeTrue();
});

it('supports fan-out to two different sub-workflows in parallel', function () {
    $parentKey = bindTestWorkflow('send-to-workflow-multi-target', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('enrich_pipe', app(PerItemPipeline::class));
            $this->addNode('alt_pipe', app(AlternatePerItemPipeline::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('enrich_pipe', ['id' => 10]),
                Send::toWorkflow('alt_pipe', ['id' => 20]),
            ], ['enrich_pipe', 'alt_pipe']);

            $this->transition('enrich_pipe', 'barrier');
            $this->transition('alt_pipe', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey);
    $fresh = $run->fresh();

    expect($fresh->status)->toBe(RunStatus::Completed);

    // Each sub-workflow ran exactly once.
    $children = WorkflowRun::where('parent_run_id', $run->id)->get();
    expect($children)->toHaveCount(2);

    $byKey = $children->groupBy('key');
    expect($byKey->has(PerItemPipeline::class))->toBeTrue();
    expect($byKey->has(AlternatePerItemPipeline::class))->toBeTrue();
});
