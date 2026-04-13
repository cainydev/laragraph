<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;
use Workbench\App\Workflows\LinearChainWorkflow;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('stores metadata on the run when provided', function () {
    $run = Laragraph::run(LinearChainWorkflow::class, [], ['eval_run' => 'abc-123', 'case' => 'foo']);

    expect($run->fresh()->metadata)->toBe(['eval_run' => 'abc-123', 'case' => 'foo']);
});

it('stores null metadata when none is provided', function () {
    $run = Laragraph::run(LinearChainWorkflow::class);

    expect($run->fresh()->metadata)->toBeNull();
});

it('does not expose metadata to nodes via state', function () {
    $key = bindTestWorkflow('metadata-isolation-test', new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('spy', new FormatNode(fn (array $state) => ['seen_keys' => array_keys($state)]));
            $this->transition(Workflow::START, 'spy');
            $this->transition('spy', Workflow::END);
        }
    });

    $run = Laragraph::run($key, ['input' => 'hello'], ['eval_run' => 'secret']);

    $fresh = $run->fresh();
    expect($fresh->state['seen_keys'])->toContain('input');
    expect($fresh->state['seen_keys'])->not->toContain('eval_run');
});
