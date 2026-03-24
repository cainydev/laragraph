<?php

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Nodes\HttpNode;
use Illuminate\Support\Facades\Http;

use function Cainy\Laragraph\Tests\makeContext;

it('makes a GET request and stores response in state', function () {
    Http::fake(['https://api.example.com/data' => Http::response(['value' => 42], 200)]);

    $node = new HttpNode(url: 'https://api.example.com/data');
    $result = $node->handle(makeContext(), []);

    expect($result['response']['status'])->toBe(200);
    expect($result['response']['ok'])->toBeTrue();
    expect($result['response']['body'])->toBe(['value' => 42]);
});

it('makes a POST request with body from state', function () {
    Http::fake(['https://api.example.com/submit' => Http::response(['id' => 1], 201)]);

    $node = new HttpNode(url: 'https://api.example.com/submit', method: 'POST', bodyKey: 'payload');
    $state = ['payload' => ['name' => 'Alice']];

    $result = $node->handle(makeContext(), $state);

    expect($result['response']['status'])->toBe(201);
    Http::assertSent(fn ($req) => $req->url() === 'https://api.example.com/submit' && $req->method() === 'POST');
});

it('interpolates {state.key} placeholders in the URL', function () {
    Http::fake(['https://api.example.com/users/42' => Http::response(['name' => 'Bob'], 200)]);

    $node = new HttpNode(url: 'https://api.example.com/users/{state.user_id}');
    $result = $node->handle(makeContext(), ['user_id' => 42]);

    Http::assertSent(fn ($req) => $req->url() === 'https://api.example.com/users/42');
    expect($result['response']['ok'])->toBeTrue();
});

it('interpolates nested {state.key} dot-paths', function () {
    Http::fake(['https://api.example.com/orgs/acme' => Http::response([], 200)]);

    $node = new HttpNode(url: 'https://api.example.com/orgs/{state.org.slug}');
    $result = $node->handle(makeContext(), ['org' => ['slug' => 'acme']]);

    Http::assertSent(fn ($req) => $req->url() === 'https://api.example.com/orgs/acme');
});

it('stores response under a custom responseKey', function () {
    Http::fake(['https://api.example.com/' => Http::response(['ok' => true], 200)]);

    $node = new HttpNode(url: 'https://api.example.com/', responseKey: 'api_result');
    $result = $node->handle(makeContext(), []);

    expect($result)->toHaveKey('api_result');
    expect($result)->not->toHaveKey('response');
});

it('marks non-2xx responses as not ok', function () {
    Http::fake(['https://api.example.com/fail' => Http::response(['error' => 'bad'], 500)]);

    $node = new HttpNode(url: 'https://api.example.com/fail');
    $result = $node->handle(makeContext(), []);

    expect($result['response']['ok'])->toBeFalse();
    expect($result['response']['status'])->toBe(500);
});

it('serializes to array', function () {
    $node = new HttpNode(
        url: 'https://example.com/{state.id}',
        method: 'POST',
        headers: ['X-Key' => 'val'],
        bodyKey: 'payload',
        responseKey: 'result',
    );

    expect($node->toArray())->toBe([
        '__synthetic' => 'http',
        'url' => 'https://example.com/{state.id}',
        'method' => 'POST',
        'headers' => ['X-Key' => 'val'],
        'body_key' => 'payload',
        'response_key' => 'result',
    ]);
});

it('deserializes from array', function () {
    $node = HttpNode::fromArray([
        '__synthetic' => 'http',
        'url' => 'https://example.com',
        'method' => 'DELETE',
        'headers' => [],
        'body_key' => null,
        'response_key' => 'resp',
    ]);

    expect($node->url)->toBe('https://example.com');
    expect($node->method)->toBe('DELETE');
    expect($node->responseKey)->toBe('resp');
});

it('round-trips via Workflow::fromJson()', function () {
    $workflow = Workflow::create()
        ->addNode('fetch', new HttpNode(url: 'https://api.example.com/data', responseKey: 'data'))
        ->transition(Workflow::START, 'fetch')
        ->transition('fetch', Workflow::END);

    $compiled = Workflow::fromJson($workflow->toJson());

    $node = $compiled->resolveNode('fetch');
    expect($node)->toBeInstanceOf(HttpNode::class);
    expect($node->url)->toBe('https://api.example.com/data');
    expect($node->responseKey)->toBe('data');
});
