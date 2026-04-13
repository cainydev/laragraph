<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

function makeFailingNodeInstance(): Node
{
    return new class implements Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            throw new RuntimeException('Intentional failure');
        }
    };
}

it('sets run status to Failed when a node throws', function () {
    $key = bindTestWorkflow('fail-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('boom', makeFailingNodeInstance());
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
        // Sync queue propagates the exception
    }

    $run = WorkflowRun::latest()->first();
    expect($run->status)->toBe(RunStatus::Failed);
});

it('records error details in state on failure', function () {
    $key = bindTestWorkflow('fail-error-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('boom', makeFailingNodeInstance());
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
        // expected
    }

    $run = WorkflowRun::latest()->first();
    expect($run->state)->toHaveKey('error');
    expect($run->state['error']['message'])->toContain('Intentional failure');
    expect($run->state['error']['node'])->toBe('boom');
});

it('rejects resuming a failed workflow', function () {
    $key = bindTestWorkflow('fail-resume-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('boom', makeFailingNodeInstance());
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
        // expected
    }

    $run = WorkflowRun::latest()->first();

    expect(fn () => Laragraph::resume($run->id))
        ->toThrow(RuntimeException::class, 'not paused');
});
