<?php

use Cainy\Laragraph\Edges\Edge;

it('evaluates to true when no condition', function () {
    $edge = new Edge('a', 'b');

    expect($edge->evaluate(['any' => 'state']))->toBeTrue();
});

it('evaluates Closure condition returning true', function () {
    $edge = new Edge('a', 'b', fn (array $state) => $state['score'] > 50);

    expect($edge->evaluate(['score' => 80]))->toBeTrue();
});

it('evaluates Closure condition returning false', function () {
    $edge = new Edge('a', 'b', fn (array $state) => $state['score'] > 50);

    expect($edge->evaluate(['score' => 30]))->toBeFalse();
});

it('evaluates ExpressionLanguage string condition', function () {
    $edge = new Edge('a', 'b', "state['score'] > 50");

    expect($edge->evaluate(['score' => 80]))->toBeTrue();
    expect($edge->evaluate(['score' => 30]))->toBeFalse();
});

it('reports serializable for null and string conditions', function () {
    expect((new Edge('a', 'b'))->isSerializable())->toBeTrue();
    expect((new Edge('a', 'b', "state['x'] > 0"))->isSerializable())->toBeTrue();
});

it('reports not serializable for Closure', function () {
    $edge = new Edge('a', 'b', fn () => true);

    expect($edge->isSerializable())->toBeFalse();
});

it('round-trips via toArray and fromArray', function () {
    $edge = new Edge('node-a', 'node-b', "state['active'] == true");

    $restored = Edge::fromArray($edge->toArray());

    expect($restored->from)->toBe('node-a');
    expect($restored->to)->toBe('node-b');
    expect($restored->evaluate(['active' => true]))->toBeTrue();
    expect($restored->evaluate(['active' => false]))->toBeFalse();
});

it('throws when serializing Closure edge', function () {
    $edge = new Edge('a', 'b', fn () => true);

    expect(fn () => $edge->toArray())->toThrow(RuntimeException::class);
});
