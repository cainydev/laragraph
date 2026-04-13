<?php

use Cainy\Laragraph\Edges\BranchEdge;
use Cainy\Laragraph\Routing\Send;

it('resolves Closure returning single string', function () {
    $edge = new BranchEdge('a', fn () => 'target-b');

    expect($edge->resolve([]))->toBe(['target-b']);
});

it('resolves Closure returning array of strings', function () {
    $edge = new BranchEdge('a', fn () => ['b', 'c']);

    expect($edge->resolve([]))->toBe(['b', 'c']);
});

it('resolves Closure returning Send objects', function () {
    $edge = new BranchEdge('a', fn (array $state) => array_map(
        fn ($url) => new Send('fetcher', ['url' => $url]),
        $state['urls'],
    ));

    $result = $edge->resolve(['urls' => ['http://a.com', 'http://b.com']]);

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(Send::class);
    expect($result[0]->nodeName)->toBe('fetcher');
    expect($result[0]->payload)->toBe(['url' => 'http://a.com']);
});
