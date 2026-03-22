<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

use function Cainy\Laragraph\Tests\registerTestWorkflow;

function makeFailingNodeInstance(): Node
{
    return new class implements Node
    {
        public function getName(): string
        {
            return 'failing-node';
        }

        public function handle(\Cainy\Laragraph\Engine\NodeExecutionContext $context, array $state): array
        {
            throw new RuntimeException('Intentional failure');
        }
    };
}

it('sets run status to Failed when a node throws', function () {
    registerTestWorkflow('fail-test', Workflow::create()
        ->addNode('boom', makeFailingNodeInstance())
        ->transition(Workflow::START, 'boom')
        ->transition('boom', Workflow::END));

    try {
        Laragraph::start('fail-test');
    } catch (\Throwable) {
        // Sync queue propagates the exception
    }

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Failed);
});

it('records error details in state on failure', function () {
    registerTestWorkflow('fail-error-test', Workflow::create()
        ->addNode('boom', makeFailingNodeInstance())
        ->transition(Workflow::START, 'boom')
        ->transition('boom', Workflow::END));

    try {
        Laragraph::start('fail-error-test');
    } catch (\Throwable) {
        // expected
    }

    $run = WorkflowRun::latest()->first();
    expect($run->state)->toHaveKey('error');
    expect($run->state['error']['message'])->toContain('Intentional failure');
    expect($run->state['error']['node'])->toBe('boom');
});

it('rejects resuming a failed workflow', function () {
    registerTestWorkflow('fail-resume-test', Workflow::create()
        ->addNode('boom', makeFailingNodeInstance())
        ->transition(Workflow::START, 'boom')
        ->transition('boom', Workflow::END));

    try {
        Laragraph::start('fail-resume-test');
    } catch (\Throwable) {
        // expected
    }

    $run = WorkflowRun::latest()->first();

    expect(fn () => Laragraph::resume($run->id))
        ->toThrow(RuntimeException::class, 'not paused');
});
