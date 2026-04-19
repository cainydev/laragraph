<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasRetryPolicy;
use Cainy\Laragraph\Contracts\HasTags;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Engine\RetryPolicy;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\NodeExecution;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

class AlwaysFailingRetryNode implements HasRetryPolicy, Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        throw new RuntimeException('boom '.$context->attempt);
    }

    public function retryPolicy(): RetryPolicy
    {
        return new RetryPolicy(maxAttempts: 3);
    }
}

it('writes exactly one failure row when a node exhausts retries', function () {
    $key = bindTestWorkflow('exhaust-retry-row-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('flaky', new AlwaysFailingRetryNode);
            $this->transition(Workflow::START, 'flaky');
            $this->transition('flaky', Workflow::END);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    $rows = NodeExecution::where('node_name', 'flaky')->whereNotNull('failed_at')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->error_class)->toBe(RuntimeException::class);
    expect($rows->first()->error_message)->toContain('boom');
});

it('does not write a failure row when a node succeeds', function () {
    $key = bindTestWorkflow('success-no-row-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('ok', new FormatNode(fn () => ['ok' => true]));
            $this->transition(Workflow::START, 'ok');
            $this->transition('ok', Workflow::END);
        }
    });

    Laragraph::run($key);

    $rows = NodeExecution::whereNotNull('failed_at')->get();
    expect($rows)->toHaveCount(0);
});

it('differentiates success rows (with tags) from failure rows in the same run', function () {
    // Node that both has tags (records a success row) and fails (records a failure row).
    $key = bindTestWorkflow('mixed-rows-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('ok_with_tags', new class implements HasTags, Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    return ['passed' => true];
                }

                public function tags(): array
                {
                    return ['cost_usd' => 0.01];
                }
            });
            $this->addNode('boom', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    throw new RuntimeException('bang');
                }
            });
            $this->transition(Workflow::START, 'ok_with_tags');
            $this->transition('ok_with_tags', 'boom');
            $this->transition('boom', Workflow::END);
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    $success = NodeExecution::where('node_name', 'ok_with_tags')->get();
    expect($success)->toHaveCount(1);
    expect($success->first()->failed_at)->toBeNull();
    expect($success->first()->tags)->toBe(['cost_usd' => 0.01]);

    $failure = NodeExecution::where('node_name', 'boom')->get();
    expect($failure)->toHaveCount(1);
    expect($failure->first()->failed_at)->not->toBeNull();
    expect($failure->first()->error_class)->toBe(RuntimeException::class);
});
