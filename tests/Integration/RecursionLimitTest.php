<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Exceptions\RecursionLimitExceeded;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

// A node that always routes back to itself (infinite loop)
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
    registerTestWorkflow('loop-limit-test', Workflow::create()
        ->addNode('loop', makeInfiniteLoopNode())
        ->transition(Workflow::START, 'loop')
        ->transition('loop', 'loop') // routes back to itself
        ->withRecursionLimit(5));

    try {
        Laragraph::start('loop-limit-test');
    } catch (Throwable) {
        // sync queue re-throws
    }

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Failed);
});

it('stops execution at the configured limit', function () {
    registerTestWorkflow('count-limit-test', Workflow::create()
        ->addNode('step', makeInfiniteLoopNode())
        ->transition(Workflow::START, 'step')
        ->transition('step', 'step')
        ->withRecursionLimit(3));

    try {
        Laragraph::start('count-limit-test');
    } catch (Throwable) {}

    $run = WorkflowRun::latest()->first();
    // Should have stopped at the limit, not run indefinitely
    expect($run->node_executions)->toBeLessThanOrEqual(4); // limit + 1 attempt that trips it
    expect($run->status)->toBe(RunStatus::Failed);
});

it('does not trigger limit for workflows that complete within the limit', function () {
    registerTestWorkflow('safe-limit-test', Workflow::create()
        ->addNode('a', makeInfiniteLoopNode())
        ->addNode('b', makeInfiniteLoopNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', 'b')
        ->transition('b', Workflow::END)
        ->withRecursionLimit(10));

    $run = Laragraph::start('safe-limit-test');

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('serializes and restores recursionLimit via toJson/fromJson', function () {
    $workflow = Workflow::create()
        ->addNode('step', makeInfiniteLoopNode())
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->withRecursionLimit(42);

    $compiled = Workflow::fromJson($workflow->toJson());

    expect($compiled->getRecursionLimit())->toBe(42);
});

it('falls back to config recursion_limit when not set', function () {
    $workflow = Workflow::create()
        ->addNode('step', makeInfiniteLoopNode())
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END);

    $compiled = $workflow->compile();

    expect($compiled->getRecursionLimit())->toBe(config('laragraph.recursion_limit', 25));
});
