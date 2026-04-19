<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\NodeExecution;
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

it('records error details in routing on failure (not in user state)', function () {
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

    // User state stays clean — no engine metadata leakage.
    expect($run->state)->not->toHaveKey('error');

    // Engine records the error in the routing column.
    expect($run->routing)->toHaveKey('error');
    expect($run->routing['error']['message'])->toContain('Intentional failure');
    expect($run->routing['error']['node'])->toBe('boom');
    expect($run->routing['error']['class'])->toBe(RuntimeException::class);
});

it('persists a failed execution row on the node_executions table', function () {
    $key = bindTestWorkflow('fail-row-test', new class extends Workflow
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

    $row = NodeExecution::where('node_name', 'boom')->whereNotNull('failed_at')->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->error_class)->toBe(RuntimeException::class);
    expect($row->error_message)->toContain('Intentional failure');
    expect($row->error_trace)->not->toBeNull();
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
