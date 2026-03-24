<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Nodes\CacheNode;
use Illuminate\Support\Facades\Cache;

use function Cainy\Laragraph\Tests\makeContext;

it('reads a value from cache into state', function () {
    Cache::put('my_key', 'cached_value');

    $node = new CacheNode(operation: 'get', cacheKey: 'my_key', stateKey: 'result');
    $result = $node->handle(makeContext(), []);

    expect($result)->toBe(['result' => 'cached_value']);
});

it('returns null in state when cache key is missing', function () {
    Cache::forget('missing_key');

    $node = new CacheNode(operation: 'get', cacheKey: 'missing_key', stateKey: 'result');
    $result = $node->handle(makeContext(), []);

    expect($result['result'])->toBeNull();
});

it('writes a state value to cache', function () {
    Cache::forget('stored_key');

    $node = new CacheNode(operation: 'put', cacheKey: 'stored_key', stateKey: 'data');
    $node->handle(makeContext(), ['data' => 'hello']);

    expect(Cache::get('stored_key'))->toBe('hello');
});

it('writes to cache with a TTL', function () {
    $node = new CacheNode(operation: 'put', cacheKey: 'ttl_key', stateKey: 'val', ttl: 60);
    $node->handle(makeContext(), ['val' => 'ephemeral']);

    expect(Cache::get('ttl_key'))->toBe('ephemeral');
});

it('forgets a cache key', function () {
    Cache::put('gone_key', 'exists');

    $node = new CacheNode(operation: 'forget', cacheKey: 'gone_key', stateKey: 'irrelevant');
    $result = $node->handle(makeContext(), []);

    expect($result)->toBe([]);
    expect(Cache::has('gone_key'))->toBeFalse();
});

it('interpolates {state.key} in cache key', function () {
    Cache::put('user:99', 'data_for_99');

    $node = new CacheNode(operation: 'get', cacheKey: 'user:{state.user_id}', stateKey: 'profile');
    $result = $node->handle(makeContext(), ['user_id' => 99]);

    expect($result['profile'])->toBe('data_for_99');
});

it('throws for unknown operation', function () {
    $node = new CacheNode(operation: 'invalid', cacheKey: 'k', stateKey: 's');

    expect(fn () => $node->handle(makeContext(), []))->toThrow(InvalidArgumentException::class);
});

it('serializes to array', function () {
    $node = new CacheNode('put', 'cache_key', 'state_key', 300);

    expect($node->toArray())->toBe([
        '__synthetic' => 'cache',
        'operation' => 'put',
        'cache_key' => 'cache_key',
        'state_key' => 'state_key',
        'ttl' => 300,
    ]);
});

it('deserializes from array', function () {
    $node = CacheNode::fromArray([
        '__synthetic' => 'cache',
        'operation' => 'get',
        'cache_key' => 'k',
        'state_key' => 's',
        'ttl' => null,
    ]);

    expect($node->operation)->toBe('get');
    expect($node->cacheKey)->toBe('k');
    expect($node->ttl)->toBeNull();
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('read', new CacheNode('get', 'profile:{state.id}', 'profile'))
        ->transition(Workflow::START, 'read')
        ->transition('read', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('read');
    expect($node)->toBeInstanceOf(CacheNode::class);
    expect($node->operation)->toBe('get');
    expect($node->cacheKey)->toBe('profile:{state.id}');
    expect($node->stateKey)->toBe('profile');
});
