<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

function makeInfiniteLoopNode(): Node
{
    return new class implements Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return ['count' => ($state['count'] ?? 0) + 1];
        }
    };
}

it('marks the run as Failed when recursion limit is exceeded', function () {
    $key = bindTestWorkflow('loop-limit-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('loop', makeInfiniteLoopNode());
            $this->transition(Workflow::START, 'loop');
            $this->transition('loop', 'loop');
            $this->withRecursionLimit(5);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
        // sync queue re-throws
    }

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Failed);
});

it('stops execution at the configured limit', function () {
    $key = bindTestWorkflow('count-limit-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('step', makeInfiniteLoopNode());
            $this->transition(Workflow::START, 'step');
            $this->transition('step', 'step');
            $this->withRecursionLimit(3);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    $run = WorkflowRun::latest()->first();
    expect($run->node_executions)->toBeLessThanOrEqual(4);
    expect($run->status)->toBe(RunStatus::Failed);
});

it('does not trigger limit for workflows that complete within the limit', function () {
    $key = bindTestWorkflow('safe-limit-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeInfiniteLoopNode());
            $this->addNode('b', makeInfiniteLoopNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', 'b');
            $this->transition('b', Workflow::END);
            $this->withRecursionLimit(10);
        }
    });

    $run = Laragraph::run($key);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('falls back to config recursion_limit when not set', function () {
    $workflow = new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('step', makeInfiniteLoopNode());
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }
    };

    $compiled = $workflow->compile();

    expect($compiled->getRecursionLimit())->toBe(config('laragraph.recursion_limit', 25));
});
