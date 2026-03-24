<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Integrations\Prism\ToolExecutor;

// Regression: Bug 1.1 — toJson() must inject loop nodes before serializing

function makeLoopNodeForSerial(): Node&HasLoop
{
    return new class implements Node, HasLoop
    {
        public function handle(NodeExecutionContext $context, array $state): array { return []; }

        public function loopNode(string $nodeName): Node
        {
            return new ToolExecutor($nodeName, static::class);
        }

        public function loopCondition(): string|\Closure
        {
            return 'not_empty(last(state["messages"])["tool_calls"] ?? [])';
        }
    };
}

it('toJson() includes injected loop nodes in the snapshot (bug 1.1 regression)', function () {
    $workflow = Workflow::create()
        ->addNode('agent', makeLoopNodeForSerial())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END);

    $json = $workflow->toJson();
    $data = json_decode($json, true);

    // The loop node must appear in the serialized nodes
    expect($data['nodes'])->toHaveKey('agent.__loop__');
    expect($data['nodes']['agent.__loop__']['__synthetic'])->toBe('tool_executor');
});

it('fromJson() round-trip restores the loop node injected by toJson() (bug 1.1 regression)', function () {
    $workflow = Workflow::create()
        ->addNode('agent', makeLoopNodeForSerial())
        ->transition(Workflow::START, 'agent')
        ->transition('agent', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    expect($compiled->getNodes())->toHaveKey('agent.__loop__');
    expect($compiled->resolveNode('agent.__loop__'))->toBeInstanceOf(ToolExecutor::class);
});

it('toArray() on CompiledWorkflow includes workflowName (bug 1.2 regression)', function () {
    $compiled = Workflow::create()
        ->addNode('a', new class implements Node {
            public function handle(NodeExecutionContext $c, array $s): array { return []; }
        })
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->withName('my-workflow')
        ->compile();

    $array = $compiled->toArray();

    expect($array['workflowName'])->toBe('my-workflow');
});

it('toArray() on CompiledWorkflow includes recursionLimit (bug 1.2 extension)', function () {
    $compiled = Workflow::create()
        ->addNode('a', new class implements Node {
            public function handle(NodeExecutionContext $c, array $s): array { return []; }
        })
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->withRecursionLimit(15)
        ->compile();

    expect($compiled->toArray()['recursionLimit'])->toBe(15);
});

it('child workflows preserve workflowName across startChildWorkflow snapshot', function () {
    $child = Workflow::create()
        ->addNode('step', new class implements Node {
            public function handle(NodeExecutionContext $c, array $s): array { return []; }
        })
        ->transition(Workflow::START, 'step')
        ->transition('step', Workflow::END)
        ->withName('child-workflow')
        ->compile();

    // toArray() is what gets stored in the snapshot column
    $snapshot = $child->toArray();
    expect($snapshot['workflowName'])->toBe('child-workflow');

    // and the name() method should work on the compiled workflow
    expect($child->name())->toBe('child-workflow');
});
