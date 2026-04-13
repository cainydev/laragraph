<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\bindTestWorkflow;

it('calls onStarting when a workflow is started', function () {
    $spy = new stdClass;
    $spy->called = false;

    $key = bindTestWorkflow('hooks-starting-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }

        public function onStarting(WorkflowRun $run): void
        {
            $this->spy->called = true;
        }
    });

    Laragraph::run($key);

    expect($spy->called)->toBeTrue();
});

it('passes the WorkflowRun to onStarting', function () {
    $spy = new stdClass;
    $spy->capturedId = null;

    $key = bindTestWorkflow('hooks-starting-run-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }

        public function onStarting(WorkflowRun $run): void
        {
            $this->spy->capturedId = $run->id;
        }
    });

    $run = Laragraph::run($key);

    expect($spy->capturedId)->toBe($run->id);
});

it('calls onCompleted when a workflow finishes successfully', function () {
    $spy = new stdClass;
    $spy->called = false;

    $key = bindTestWorkflow('hooks-completed-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }

        public function onCompleted(WorkflowRun $run): void
        {
            $this->spy->called = true;
        }
    });

    Laragraph::run($key);

    expect($spy->called)->toBeTrue();
});

it('passes a Completed run to onCompleted', function () {
    $spy = new stdClass;
    $spy->capturedStatus = null;

    $key = bindTestWorkflow('hooks-completed-status-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }

        public function onCompleted(WorkflowRun $run): void
        {
            $this->spy->capturedStatus = $run->status;
        }
    });

    Laragraph::run($key);

    expect($spy->capturedStatus)->toBe(RunStatus::Completed);
});

it('calls onFailed when a node exhausts all retries', function () {
    $spy = new stdClass;
    $spy->called = false;

    $key = bindTestWorkflow('hooks-failed-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('boom', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    throw new RuntimeException('intentional');
                }
            });
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }

        public function onFailed(WorkflowRun $run, Throwable $exception): void
        {
            $this->spy->called = true;
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    expect($spy->called)->toBeTrue();
});

it('passes the exception to onFailed', function () {
    $spy = new stdClass;
    $spy->capturedMessage = null;

    $key = bindTestWorkflow('hooks-failed-exception-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('boom', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    throw new RuntimeException('something went wrong');
                }
            });
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }

        public function onFailed(WorkflowRun $run, Throwable $exception): void
        {
            $this->spy->capturedMessage = $exception->getMessage();
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    expect($spy->capturedMessage)->toContain('something went wrong');
});

it('does not call onCompleted when a workflow fails', function () {
    $spy = new stdClass;
    $spy->called = false;

    $key = bindTestWorkflow('hooks-no-completed-on-fail-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('boom', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    throw new RuntimeException('intentional');
                }
            });
            $this->transition(Workflow::START, 'boom');
            $this->transition('boom', Workflow::END);
        }

        public function onCompleted(WorkflowRun $run): void
        {
            $this->spy->called = true;
        }
    });

    try {
        Laragraph::run($key);
    } catch (Throwable) {
    }

    expect($spy->called)->toBeFalse();
});

it('does not call onFailed on a successful workflow', function () {
    $spy = new stdClass;
    $spy->called = false;

    $key = bindTestWorkflow('hooks-no-failed-on-success-test', new class($spy) extends Workflow
    {
        public function __construct(private stdClass $spy) {}

        public function definition(): void
        {
            $this->addNode('step', new FormatNode(fn () => []));
            $this->transition(Workflow::START, 'step');
            $this->transition('step', Workflow::END);
        }

        public function onFailed(WorkflowRun $run, Throwable $exception): void
        {
            $this->spy->called = true;
        }
    });

    Laragraph::run($key);

    expect($spy->called)->toBeFalse();
});
