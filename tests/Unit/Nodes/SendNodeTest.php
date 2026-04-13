<?php

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
