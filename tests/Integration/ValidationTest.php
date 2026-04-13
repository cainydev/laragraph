<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('rejects compile when no edges from START', function () {
    expect(fn () => (new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('a', new FormatNode(fn () => []));
            $this->transition('a', Workflow::END);
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'at least one edge from __START__');
});

it('rejects compile when edge targets START', function () {
    expect(fn () => (new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('a', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::START);
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'Edges to __START__');
});

it('rejects compile when edge originates from END', function () {
    expect(fn () => (new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('a', new FormatNode(fn () => []));
            $this->addNode('b', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'a');
            $this->transition('a', Workflow::END);
            $this->transition(Workflow::END, 'b');
        }
    })->compile())->toThrow(InvalidArgumentException::class, 'Edges from __END__');
});

it('runs a minimal START to END workflow', function () {
    $key = bindTestWorkflow('minimal', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('noop', new FormatNode(fn () => ['ran' => true]));
            $this->transition(Workflow::START, 'noop');
            $this->transition('noop', Workflow::END);
        }
    });

    $run = Laragraph::run($key);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
    expect($run->fresh()->state['ran'])->toBeTrue();
});
