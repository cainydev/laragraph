<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

class CascadeFailingNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        throw new RuntimeException('Child exploded');
    }
}

class CascadeFailingChildWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('boom', new CascadeFailingNode);
        $this->transition(Workflow::START, 'boom');
        $this->transition('boom', Workflow::END);
    }
}

class CascadeTolerantChildWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('boom', new CascadeFailingNode);
        $this->transition(Workflow::START, 'boom');
        $this->transition('boom', Workflow::END);
    }

    public function shouldCascadeFailure(): bool
    {
        return false;
    }
}

it('fails the parent when a child workflow fails (cascade default)', function () {
    $parentKey = bindTestWorkflow('cascade-parent-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('sub', app(CascadeFailingChildWorkflow::class));
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', Workflow::END);
        }
    });

    try {
        Laragraph::run($parentKey);
    } catch (Throwable) {
        // sync queue re-throws
    }

    $parent = WorkflowRun::where('key', $parentKey)->latest('id')->first();

    expect($parent)->not->toBeNull();
    expect($parent->fresh()->status)->toBe(RunStatus::Failed);
    expect($parent->fresh()->active_pointers)->toBe([]);
    expect($parent->fresh()->routing)->toHaveKey('error');
    expect($parent->fresh()->routing['error']['from_child'])->toBeTrue();
});

it('leaves the parent paused when the embedded child opts out of cascade', function () {
    $parentKey = bindTestWorkflow('cascade-parent-opt-out-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('sub', app(CascadeTolerantChildWorkflow::class));
            $this->transition(Workflow::START, 'sub');
            $this->transition('sub', Workflow::END);
        }
    });

    try {
        Laragraph::run($parentKey);
    } catch (Throwable) {
        // sync queue re-throws
    }

    $parent = WorkflowRun::where('key', $parentKey)->latest('id')->first();

    expect($parent)->not->toBeNull();
    // Parent stays paused — caller can decide what to do with the failed child.
    expect($parent->fresh()->status)->toBe(RunStatus::Paused);
});
