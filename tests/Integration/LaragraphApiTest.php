<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Nodes\FormatNode;
use Workbench\App\Workflows\LinearChainWorkflow;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('starts a workflow and runs to completion', function () {
    $run = Laragraph::run(LinearChainWorkflow::class);

    expect($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('starts with initial state and processes it', function () {
    $key = bindTestWorkflow('echo-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('echo', new FormatNode(fn (array $state) => ['echoed' => $state['input'] ?? 'none']));
            $this->transition(Workflow::START, 'echo');
            $this->transition('echo', Workflow::END);
        }
    });

    $run = Laragraph::run($key, ['input' => 'hello']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['echoed'])->toBe('hello');
});

it('pauses via interrupt_before', function () {
    $key = bindTestWorkflow('pause-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => ['done' => true]));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
            $this->interruptBefore('step');
        }
    });

    $run = Laragraph::run($key);

    expect($run->fresh()->status)->toBe(RunStatus::Paused);
});

it('rejects pausing a non-running workflow', function () {
    $run = Laragraph::run(LinearChainWorkflow::class);
    expect($run->fresh()->status)->toBe(RunStatus::Completed);

    expect(fn () => Laragraph::pause($run->id))->toThrow(RuntimeException::class, 'not running');
});

it('resumes a paused workflow', function () {
    $key = bindTestWorkflow('resume-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn (array $state) => ['result' => $state['input'] ?? 'default']));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
            $this->interruptBefore('step');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['input' => 'from-human']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['result'])->toBe('from-human');
});

it('rejects resuming a non-paused workflow', function () {
    $run = Laragraph::run(LinearChainWorkflow::class);

    expect(fn () => Laragraph::resume($run->id))
        ->toThrow(RuntimeException::class, 'not paused');
});

it('merges additional state on resume', function () {
    $key = bindTestWorkflow('merge-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('check', new FormatNode(fn (array $state) => ['saw_extra' => $state['extra'] ?? false]));
            $this->transition(Workflow::START, 'check');
            $this->transition('check', Workflow::END);
            $this->interruptBefore('check');
        }
    });

    $run = Laragraph::run($key, ['original' => true]);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::resume($run->id, ['extra' => 'injected']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Completed);
    expect($fresh->state['saw_extra'])->toBe('injected');
    expect($fresh->state['original'])->toBeTrue();
});

it('aborts a workflow', function () {
    $key = bindTestWorkflow('abort-test', new class extends Workflow {
        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
            $this->interruptBefore('step');
        }
    });

    $run = Laragraph::run($key);
    expect($run->fresh()->status)->toBe(RunStatus::Paused);

    Laragraph::abort($run->id);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(RunStatus::Failed);
    expect($fresh->active_pointers)->toBe([]);
});
