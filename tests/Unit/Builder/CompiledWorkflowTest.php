<?php

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Edges\Edge;
use Cainy\Laragraph\Reducers\SmartReducer;

function makeTestNode(string $name = 'test'): Node
{
    return new class($name) implements Node
    {
        public function __construct(private readonly string $name) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function handle(\Cainy\Laragraph\Engine\NodeExecutionContext $context, array $state): array
        {
            return [];
        }
    };
}

it('resolves a Node instance directly', function () {
    $node = makeTestNode('a');
    $compiled = new CompiledWorkflow(
        nodes: ['a' => $node],
        edges: [new Edge(Workflow::START, 'a'), new Edge('a', Workflow::END)],
    );

    expect($compiled->resolveNode('a'))->toBe($node);
});

it('resolves a class-string node via container', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['a' => SmartReducer::class], // Not Node but tests app() resolution
        edges: [],
    );

    // This would throw if the class doesn't implement Node in real usage,
    // but we're testing the resolution path
    expect(fn () => $compiled->resolveNode('a'))->not->toThrow(InvalidArgumentException::class);
});

it('throws for unknown node name', function () {
    $compiled = new CompiledWorkflow(nodes: [], edges: []);

    expect(fn () => $compiled->resolveNode('unknown'))
        ->toThrow(InvalidArgumentException::class, 'not defined');
});

it('resolves next nodes with unconditional edge', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['a' => makeTestNode(), 'b' => makeTestNode()],
        edges: [new Edge('a', 'b')],
    );

    expect($compiled->resolveNextNodes('a', []))->toBe(['b']);
});

it('resolves next nodes with conditional edge that passes', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['a' => makeTestNode(), 'b' => makeTestNode()],
        edges: [new Edge('a', 'b', fn (array $s) => $s['go'] ?? false)],
    );

    expect($compiled->resolveNextNodes('a', ['go' => true]))->toBe(['b']);
});

it('returns empty when conditional edge fails', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['a' => makeTestNode(), 'b' => makeTestNode()],
        edges: [new Edge('a', 'b', fn (array $s) => $s['go'] ?? false)],
    );

    expect($compiled->resolveNextNodes('a', ['go' => false]))->toBe([]);
});

it('resolves next nodes with branch edge', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['a' => makeTestNode(), 'b' => makeTestNode(), 'c' => makeTestNode()],
        edges: [new BranchEdge('a', fn () => ['b', 'c'])],
    );

    expect($compiled->resolveNextNodes('a', []))->toBe(['b', 'c']);
});

it('returns start nodes from START pseudo-node', function () {
    $compiled = new CompiledWorkflow(
        nodes: ['first' => makeTestNode()],
        edges: [new Edge(Workflow::START, 'first')],
    );

    expect($compiled->getStartNodes())->toBe(['first']);
});

it('returns default reducer when none specified', function () {
    $compiled = new CompiledWorkflow(nodes: [], edges: []);

    expect($compiled->getReducer())->toBeInstanceOf(StateReducerInterface::class);
});

it('returns custom reducer when specified', function () {
    $compiled = new CompiledWorkflow(
        nodes: [],
        edges: [],
        reducerClass: SmartReducer::class,
    );

    expect($compiled->getReducer())->toBeInstanceOf(SmartReducer::class);
});

it('checks interrupt_before configuration', function () {
    $compiled = new CompiledWorkflow(
        nodes: [],
        edges: [],
        interruptBefore: ['review'],
    );

    expect($compiled->shouldInterruptBefore('review'))->toBeTrue();
    expect($compiled->shouldInterruptBefore('other'))->toBeFalse();
});

it('checks interrupt_after configuration', function () {
    $compiled = new CompiledWorkflow(
        nodes: [],
        edges: [],
        interruptAfter: ['drafter'],
    );

    expect($compiled->shouldInterruptAfter('drafter'))->toBeTrue();
    expect($compiled->shouldInterruptAfter('other'))->toBeFalse();
});
