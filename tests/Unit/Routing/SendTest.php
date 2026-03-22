<?php

use Cainy\Laragraph\Routing\Send;

it('stores nodeName and payload', function () {
    $send = new Send('worker', ['url' => 'http://example.com']);

    expect($send->nodeName)->toBe('worker');
    expect($send->payload)->toBe(['url' => 'http://example.com']);
});

it('supports empty payload', function () {
    $send = new Send('noop', []);

    expect($send->payload)->toBe([]);
});
