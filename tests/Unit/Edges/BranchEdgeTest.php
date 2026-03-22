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

it('resolves ExpressionLanguage string', function () {
    $edge = new BranchEdge('a', "state['approved'] ? 'publish' : 'reject'");

    expect($edge->resolve(['approved' => true]))->toBe(['publish']);
    expect($edge->resolve(['approved' => false]))->toBe(['reject']);
});

it('reports serializable for string resolver', function () {
    expect((new BranchEdge('a', "state['x']"))->isSerializable())->toBeTrue();
});

it('reports not serializable for Closure', function () {
    expect((new BranchEdge('a', fn () => 'b'))->isSerializable())->toBeFalse();
});

it('round-trips via toArray and fromArray with string resolver', function () {
    $edge = new BranchEdge('node-a', "state['next']", ['b', 'c']);
    $restored = BranchEdge::fromArray($edge->toArray());

    expect($restored->from)->toBe('node-a');
    expect($restored->resolve(['next' => 'b']))->toBe(['b']);
});

it('throws when serializing Closure without targets', function () {
    $edge = new BranchEdge('a', fn () => 'b');

    expect(fn () => $edge->toArray())->toThrow(RuntimeException::class);
});

it('serializes Closure branch with declared targets for visualization', function () {
    $edge = new BranchEdge('a', fn () => 'b', ['b', 'c']);
    $data = $edge->toArray();

    expect($data['type'])->toBe('branch');
    expect($data['from'])->toBe('a');
    expect($data['targets'])->toBe(['b', 'c']);
    expect($data)->not->toHaveKey('resolver');
});
