<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Reducers\SmartReducer;

function makeStubNode(): Node
{
    return new class implements Node
    {
        public function handle(NodeExecutionContext $context, array $state): array
        {
            return [];
        }
    };
}

it('creates a workflow via definition() and compiles', function () {
    $compiled = (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
        }
    })->compile();

    expect($compiled->getNodes())->toHaveCount(1);
    expect($compiled->getStartNodes())->toBe(['a']);
});

it('validates unknown from node in edge', function () {
    expect(fn () => (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition('unknown', 'a');
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
        }
    })->compile())->toThrow(InvalidArgumentException::class, "unknown 'from' node [unknown]");
});

it('validates unknown to node in edge', function () {
    expect(fn () => (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', 'unknown');
        }
    })->compile())->toThrow(InvalidArgumentException::class, "unknown 'to' node [unknown]");
});

it('validates START has outgoing edges', function () {
    expect(fn () => (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition('a', Workflow::END);
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'at least one edge from __START__');
});

it('rejects edges TO START', function () {
    expect(fn () => (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::START);
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'Edges to __START__');
});

it('rejects edges FROM END', function () {
    expect(fn () => (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->addNode('b', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
            $this->transition(Workflow::END, 'b');
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'Edges from __END__');
});

it('allows START to END minimal workflow', function () {
    $compiled = (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
        }
    })->compile();

    expect($compiled->getStartNodes())->toBe(['a']);
});

it('compiles with custom reducer class', function () {
    $compiled = (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
            $this->withReducer(SmartReducer::class);
        }
    })->compile();

    expect($compiled->getReducer())->toBeInstanceOf(SmartReducer::class);
});

it('compiles with interrupt configuration', function () {
    $compiled = (new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode()::class);
            $this->addNode('b', makeStubNode()::class);
            $this->transition(Workflow::START, 'a');
            $this->transition('a', 'b');
            $this->transition('b', Workflow::END);
            $this->interruptBefore('a');
            $this->interruptAfter('b');
        }
    })->compile();

    expect($compiled->shouldInterruptBefore('a'))->toBeTrue();
    expect($compiled->shouldInterruptBefore('b'))->toBeFalse();
    expect($compiled->shouldInterruptAfter('b'))->toBeTrue();
    expect($compiled->shouldInterruptAfter('a'))->toBeFalse();
});

it('calls definition() when compiling a subclass', function () {
    $workflow = new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('defined', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    return [];
                }
            });
            $this->transition(Workflow::START, 'defined');
            $this->transition('defined', Workflow::END);
        }
    };

    $compiled = $workflow->compile();

    expect($compiled->getNodes())->toHaveKey('defined');
    expect($compiled->getStartNodes())->toBe(['defined']);
});

it('compile() is idempotent on repeated calls', function () {
    $workflow = new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('a', makeStubNode());
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
        }
    };

    $first = $workflow->compile();
    $second = $workflow->compile();

    expect($second->getNodes())->toHaveCount(1);
    expect($second->getEdges())->toHaveCount($first->getEdges() !== [] ? count($first->getEdges()) : 2);
});
