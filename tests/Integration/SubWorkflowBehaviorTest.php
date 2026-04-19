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

// ─── Delta semantics: embedded (non-Send) path returns child diff ────────────

class EmbeddedChild extends Workflow
{
    public function definition(): void
    {
        $this->addNode('inner', new FormatNode(fn (array $s) => [
            'derived' => ($s['input'] ?? 0) * 2,
        ]));
        $this->transition(Workflow::START, 'inner');
        $this->transition('inner', Workflow::END);
    }
}

it('embedded sub-workflow merges diff (not full state) into parent', function () {
    $parentKey = bindTestWorkflow('embedded-delta-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('sub', app(EmbeddedChild::class));
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', Workflow::END);
        }
    });

    $run = Laragraph::run($parentKey, ['input' => 7, 'keep' => 'x'])->fresh();

    expect($run->status)->toBe(RunStatus::Completed);
    // Input keys preserved, derived appears from child.
    expect($run->state['input'])->toBe(7);
    expect($run->state['keep'])->toBe('x');
    expect($run->state['derived'])->toBe(14);
});

it('Send-dispatched sub-workflow does not see parent state', function () {
    $parentKey = bindTestWorkflow('send-sub-isolation-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('pipeline', app(EmbeddedChild::class));
            $this->addNode('barrier', new BarrierNode);

            $this->branch(Workflow::START, fn () => [
                Send::toWorkflow('pipeline', ['input' => 7]),
            ], ['pipeline']);

            $this->transition('pipeline', 'barrier');
            $this->transition('barrier', Workflow::END);
        }
    });

    // Parent has 'keep' key; Send payload has only 'input'.
    $run = Laragraph::run($parentKey, ['keep' => 'x'])->fresh();

    expect($run->status)->toBe(RunStatus::Completed);

    $child = WorkflowRun::where('parent_run_id', $run->id)->first();
    // Child never saw 'keep'; only its payload.
    expect($child->state)->not->toHaveKey('keep');
    expect($child->state['input'])->toBe(7);
    expect($child->state['derived'])->toBe(14);
});

// ─── Two-level nesting: grandparent metadata available two nodes deep ────────

class GrandchildCaptureNode implements Node
{
    public static ?int $topRunId = null;

    public static ?array $topMetadata = null;

    public static function reset(): void
    {
        self::$topRunId = null;
        self::$topMetadata = null;
    }

    public function handle(NodeExecutionContext $context, array $state): array
    {
        // Walk parent chain to the top.
        self::$topRunId = $context->parentRunId;
        self::$topMetadata = $context->parentMetadata();

        return [];
    }
}

class GrandchildWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('leaf', new GrandchildCaptureNode);
        $this->transition(Workflow::START, 'leaf');
        $this->transition('leaf', Workflow::END);
    }
}

class MiddleWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('inner', app(GrandchildWorkflow::class));
        $this->transition(Workflow::START, 'inner');
        $this->transition('inner', Workflow::END);
    }
}

it('exposes immediate parent id/metadata at each nesting level', function () {
    GrandchildCaptureNode::reset();

    $topKey = bindTestWorkflow('two-level-nest-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('middle', app(MiddleWorkflow::class));
            $this->transition(Workflow::START, 'middle');
            $this->transition('middle', Workflow::END);
        }
    });

    $top = Laragraph::run($topKey, [], ['top_tag' => 'root']);

    expect($top->fresh()->status)->toBe(RunStatus::Completed);

    // The leaf node's immediate parent is the middle child run — not the top.
    $children = WorkflowRun::where('parent_run_id', $top->id)->get();
    expect($children)->toHaveCount(1);
    $middle = $children->first();

    expect(GrandchildCaptureNode::$topRunId)->toBe($middle->id);
    // Middle run was created with no explicit metadata, so parentMetadata() is null.
    expect(GrandchildCaptureNode::$topMetadata)->toBeNull();
});
