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
