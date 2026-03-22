<?php

use Cainy\Laragraph\Nodes\FormatNode;

use function Cainy\Laragraph\Tests\makeContext;

it('transforms state via closure', function () {
    $node = new FormatNode(fn (array $state) => ['summary' => implode(', ', $state['items'])]);

    $mutation = $node->handle(makeContext(), ['items' => ['a', 'b', 'c']]);

    expect($mutation)->toBe(['summary' => 'a, b, c']);
});

it('receives isolatedPayload as second argument', function () {
    $node = new FormatNode(fn (array $state, ?array $payload) => [
        'merged' => ($payload['value'] ?? 'none').':'.($state['prefix'] ?? ''),
    ]);

    $mutation = $node->handle(makeContext(isolatedPayload: ['value' => 'data']), ['prefix' => 'pre']);

    expect($mutation['merged'])->toBe('data:pre');
});

it('can return empty mutation', function () {
    $node = new FormatNode(fn () => []);

    expect($node->handle(makeContext(), []))->toBe([]);
});
