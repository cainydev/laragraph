<?php

use Cainy\Laragraph\Reducers\SmartReducer;

beforeEach(function () {
    $this->reducer = new SmartReducer();
});

it('overwrites scalar values', function () {
    $state = $this->reducer->reduce(['name' => 'old'], ['name' => 'new']);

    expect($state['name'])->toBe('new');
});

it('adds new keys', function () {
    $state = $this->reducer->reduce(['a' => 1], ['b' => 2]);

    expect($state)->toBe(['a' => 1, 'b' => 2]);
});

it('appends list to existing list', function () {
    $state = $this->reducer->reduce(
        ['log' => ['a', 'b']],
        ['log' => ['c']],
    );

    expect($state['log'])->toBe(['a', 'b', 'c']);
});

it('does not merge list into associative array', function () {
    $state = $this->reducer->reduce(
        ['config' => ['key' => 'value']],
        ['config' => ['new_item']],
    );

    // Should overwrite, not merge (associative + list = overwrite)
    expect($state['config'])->toBe(['new_item']);
});

it('does not merge associative into list', function () {
    $state = $this->reducer->reduce(
        ['items' => ['a', 'b']],
        ['items' => ['key' => 'value']],
    );

    // Mutation is associative, so it overwrites
    expect($state['items'])->toBe(['key' => 'value']);
});

it('handles empty mutation', function () {
    $original = ['a' => 1, 'b' => 2];
    $state = $this->reducer->reduce($original, []);

    expect($state)->toBe($original);
});

it('handles empty current state', function () {
    $state = $this->reducer->reduce([], ['name' => 'test']);

    expect($state)->toBe(['name' => 'test']);
});

it('handles nested arrays as scalars when not lists', function () {
    $state = $this->reducer->reduce(
        ['error' => ['node' => 'a', 'message' => 'fail']],
        ['error' => ['node' => 'b', 'message' => 'also fail']],
    );

    // Associative arrays are overwritten, not merged
    expect($state['error'])->toBe(['node' => 'b', 'message' => 'also fail']);
});
