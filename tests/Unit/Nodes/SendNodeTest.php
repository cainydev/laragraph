<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Nodes\SendNode;
use Cainy\Laragraph\Routing\Send;

use function Cainy\Laragraph\Tests\makeContext;

it('returns one Send per item in the source key', function () {
    $node = new SendNode(sourceKey: 'urls', targetNode: 'fetcher', payloadKey: 'url');

    $result = $node->handle(makeContext(), ['urls' => ['http://a.com', 'http://b.com', 'http://c.com']]);

    expect($result)->toHaveCount(3);
    expect($result[0])->toBeInstanceOf(Send::class);
    expect($result[0]->nodeName)->toBe('fetcher');
    expect($result[0]->payload)->toBe(['url' => 'http://a.com']);
    expect($result[2]->payload)->toBe(['url' => 'http://c.com']);
});

it('returns empty array when source key is absent', function () {
    $node = new SendNode(sourceKey: 'items', targetNode: 'worker', payloadKey: 'item');

    expect($node->handle(makeContext(), []))->toBe([]);
});

it('returns empty array when source list is empty', function () {
    $node = new SendNode(sourceKey: 'items', targetNode: 'worker', payloadKey: 'item');

    expect($node->handle(makeContext(), ['items' => []]))->toBe([]);
});

it('serializes to array', function () {
    $node = new SendNode('urls', 'fetcher', 'url');

    expect($node->toArray())->toBe([
        '__synthetic' => 'send',
        'source_key' => 'urls',
        'target_node' => 'fetcher',
        'payload_key' => 'url',
    ]);
});

it('deserializes from array', function () {
    $node = SendNode::fromArray([
        '__synthetic' => 'send',
        'source_key' => 'items',
        'target_node' => 'processor',
        'payload_key' => 'item',
    ]);

    expect($node->sourceKey)->toBe('items');
    expect($node->targetNode)->toBe('processor');
    expect($node->payloadKey)->toBe('item');
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
<<<<<<< HEAD:tests/Unit/Nodes/MapNodeTest.php
        ->addNode('split', new MapNode('jobs', 'worker', 'job'))
=======
        ->addNode('split', new SendNode('jobs', 'worker', 'job'))
>>>>>>> ff094b3 (refactor: rename MapNode → SendNode, each() → send(), add PHPStan baseline, rewrite README):tests/Unit/Nodes/SendNodeTest.php
        ->addNode('worker', new class implements Node
        {
            public function handle(NodeExecutionContext $c, array $s): array
            {
                return [];
            }
        })
        ->transition(Workflow::START, 'split')
        ->transition('split', 'worker')
        ->transition('worker', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('split');
    expect($node)->toBeInstanceOf(SendNode::class);
    expect($node->sourceKey)->toBe('jobs');
    expect($node->targetNode)->toBe('worker');
    expect($node->payloadKey)->toBe('job');
});
