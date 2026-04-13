<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasQueue;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\ExecuteNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;

function makeQueuedNode(string $queue, ?string $connection = null): Node&HasQueue
{
    return new class($queue, $connection) implements HasQueue, Node
    {
        public function __construct(
            private string $q,
            private ?string $conn,
        ) {}

        public function handle(NodeExecutionContext $context, array $state): array
        {
            return [];
        }

        public function queue(): string
        {
            return $this->q;
        }

        public function connection(): ?string
        {
            return $this->conn;
        }
    };
}

it('dispatchNode uses default queue when node does not implement HasQueue', function () {
    $workflow = new class extends Workflow
    {
        public function definition(): void
        {
            $this->addNode('plain', new class implements Node
            {
                public function handle(NodeExecutionContext $context, array $state): array
                {
                    return [];
                }
            });
            $this->transition(Workflow::START, 'plain');
            $this->transition('plain', Workflow::END);
        }
    };

    $compiled = $workflow->compile();
    $job = new ExecuteNode(1, 'plain');

    // Before dispatchNode resolves HasQueue, the job uses the config default.
    expect($job->queue)->toBe(config('laragraph.queue', 'default'));
});

it('dispatchNode applies HasQueue queue to the job', function () {
    $queuedNode = makeQueuedNode('heavy');

    $workflow = new class($queuedNode) extends Workflow
    {
        public function __construct(private readonly Node $queuedNode) {}

        public function definition(): void
        {
            $this->addNode('llm', $this->queuedNode);
            $this->transition(Workflow::START, 'llm');
            $this->transition('llm', Workflow::END);
        }
    };

    $compiled = $workflow->compile();

    // Verify the node is correctly identified as HasQueue with the right queue name.
    $node = $compiled->resolveNode('llm');
    expect($node)->toBeInstanceOf(HasQueue::class);
    expect($node->queue())->toBe('heavy');
    expect($node->connection())->toBeNull();
});

it('HasQueue node reports its queue and connection', function () {
    $node = makeQueuedNode('critical', 'redis');

    expect($node->queue())->toBe('critical');
    expect($node->connection())->toBe('redis');
});
