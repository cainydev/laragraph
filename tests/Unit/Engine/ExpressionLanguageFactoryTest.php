<?php

use Cainy\Laragraph\Engine\Concerns\EvaluatesExpressions;
use Cainy\Laragraph\Routing\Send;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

// Minimal test double that exposes the trait's protected method publicly
$factory = new class
{
    use EvaluatesExpressions;

    public function make(): ExpressionLanguage
    {
        return $this->makeExpressionLanguage();
    }
};

it('last() returns last element of array', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('last(items)', ['items' => [1, 2, 3]]);
    expect($result)->toBe(3);
});

it('last() returns null for empty array', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('last(items)', ['items' => []]);
    expect($result)->toBeNull();
});

it('first() returns first element of array', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('first(items)', ['items' => [10, 20, 30]]);
    expect($result)->toBe(10);
});

it('count() returns array length', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('count(items)', ['items' => ['a', 'b', 'c']]);
    expect($result)->toBe(3);
});

it('empty() returns true for empty array', function () use ($factory) {
    $el = $factory->make();
    expect($el->evaluate('empty(items)', ['items' => []]))->toBeTrue();
    expect($el->evaluate('empty(items)', ['items' => [1]]))->toBeFalse();
});

it('not_empty() returns true for non-empty value', function () use ($factory) {
    $el = $factory->make();
    expect($el->evaluate('not_empty(items)', ['items' => [1, 2]]))->toBeTrue();
    expect($el->evaluate('not_empty(items)', ['items' => []]))->toBeFalse();
});

it('get() accesses nested values with dot notation', function () use ($factory) {
    $el = $factory->make();
    $state = ['meta' => ['score' => 42]];
    $result = $el->evaluate('get(state, "meta.score", 0)', ['state' => $state]);
    expect($result)->toBe(42);
});

it('get() returns default when key is missing', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('get(state, "missing.key", 99)', ['state' => []]);
    expect($result)->toBe(99);
});

it('has_value() checks array membership', function () use ($factory) {
    $el = $factory->make();
    expect($el->evaluate('has_value(items, "b")', ['items' => ['a', 'b', 'c']]))->toBeTrue();
    expect($el->evaluate('has_value(items, "z")', ['items' => ['a', 'b', 'c']]))->toBeFalse();
});

it('keys() returns array keys', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('keys(state)', ['state' => ['a' => 1, 'b' => 2]]);
    expect($result)->toBe(['a', 'b']);
});

it('sum() sums numeric values', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('sum(nums)', ['nums' => [1, 2, 3, 4]]);
    expect($result)->toBe(10);
});

it('join() implodes array', function () use ($factory) {
    $el = $factory->make();
    $result = $el->evaluate('join(items, ", ")', ['items' => ['a', 'b', 'c']]);
    expect($result)->toBe('a, b, c');
});

it('each() returns Send objects for each item', function () use ($factory) {
    $el = $factory->make();

    $result = $el->evaluate(
        "each('worker', state['urls'], 'url')",
        ['state' => ['urls' => ['http://a.com', 'http://b.com']]],
    );

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(Send::class);
    expect($result[0]->nodeName)->toBe('worker');
    expect($result[0]->payload)->toBe(['url' => 'http://a.com']);
    expect($result[1]->payload)->toBe(['url' => 'http://b.com']);
});

it('each() returns empty array for empty items', function () use ($factory) {
    $el = $factory->make();

    $result = $el->evaluate(
        "each('worker', state['urls'], 'url')",
        ['state' => ['urls' => []]],
    );

    expect($result)->toBe([]);
});

it('not_empty can check tool_calls on last message', function () use ($factory) {
    $el = $factory->make();

    $stateWithTools = ['messages' => [
        ['type' => 'assistant', 'tool_calls' => [['id' => '1', 'name' => 'search']]],
    ]];
    $stateNoTools = ['messages' => [
        ['type' => 'assistant', 'tool_calls' => []],
    ]];

    expect($el->evaluate('not_empty(last(state["messages"])["tool_calls"] ?? [])', ['state' => $stateWithTools]))->toBeTrue();
    expect($el->evaluate('not_empty(last(state["messages"])["tool_calls"] ?? [])', ['state' => $stateNoTools]))->toBeFalse();
});
