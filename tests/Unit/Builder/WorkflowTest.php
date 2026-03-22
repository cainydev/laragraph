<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Reducers\SmartReducer;

function makeStubNode(): Node
{
    return new class implements Node
    {
        public function getName(): string
        {
            return 'stub';
        }

        public function handle(NodeExecutionContext $context, array $state): array
        {
            return [];
        }
    };
}

it('creates a workflow with fluent API and compiles', function () {
    $compiled = Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->compile();

    expect($compiled->getNodes())->toHaveCount(1);
    expect($compiled->getStartNodes())->toBe(['a']);
});

it('validates unknown from node in edge', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition('unknown', 'a')
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->compile()
    )->toThrow(InvalidArgumentException::class, "unknown 'from' node [unknown]");
});

it('validates unknown to node in edge', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', 'unknown')
        ->compile()
    )->toThrow(InvalidArgumentException::class, "unknown 'to' node [unknown]");
});

it('validates START has outgoing edges', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition('a', Workflow::END)
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'at least one edge from __START__');
});

it('rejects edges TO START', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::START)
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'Edges to __START__');
});

it('rejects edges FROM END', function () {
    expect(fn () => Workflow::create()
        ->addNode('a', makeStubNode())
        ->addNode('b', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->transition(Workflow::END, 'b')
        ->compile()
    )->toThrow(InvalidArgumentException::class, 'Edges from __END__');
});

it('serializes and deserializes via toJson/fromJson', function () {
    $workflow = Workflow::create()
        ->addNode('a', makeStubNode()::class)
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END);

    $json = $workflow->toJson();
    $compiled = Workflow::fromJson($json);

    expect($compiled->getStartNodes())->toBe(['a']);
});

it('rejects serialization of Closure edges', function () {
    $workflow = Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a', fn () => true)
        ->transition('a', Workflow::END);

    expect(fn () => $workflow->toJson())->toThrow(RuntimeException::class);
});

it('allows START to END minimal workflow via branch', function () {
    $compiled = Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->compile();

    expect($compiled->getStartNodes())->toBe(['a']);
});

it('compiles with custom reducer class', function () {
    $compiled = Workflow::create()
        ->addNode('a', makeStubNode())
        ->transition(Workflow::START, 'a')
        ->transition('a', Workflow::END)
        ->withReducer(SmartReducer::class)
        ->compile();

    expect($compiled->getReducer())->toBeInstanceOf(SmartReducer::class);
});

it('serializes and deserializes interrupt configuration', function () {
    $workflow = Workflow::create()
        ->addNode('a', makeStubNode()::class)
        ->addNode('b', makeStubNode()::class)
        ->transition(Workflow::START, 'a')
        ->transition('a', 'b')
        ->transition('b', Workflow::END)
        ->interruptBefore('a')
        ->interruptAfter('b');

    $json = $workflow->toJson();
    $compiled = Workflow::fromJson($json);

    expect($compiled->shouldInterruptBefore('a'))->toBeTrue();
    expect($compiled->shouldInterruptBefore('b'))->toBeFalse();
    expect($compiled->shouldInterruptAfter('b'))->toBeTrue();
    expect($compiled->shouldInterruptAfter('a'))->toBeFalse();
});
