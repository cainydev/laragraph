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

it('has_tool_calls returns true when tool_calls present', function () use ($factory) {
    $el = $factory->make();
    $messages = [
        ['role' => 'assistant', 'tool_calls' => [['id' => '1', 'name' => 'search']]],
    ];

    $result = $el->evaluate('has_tool_calls(messages)', ['messages' => $messages]);

    expect($result)->toBeTrue();
});

it('has_tool_calls returns false when no tool_calls', function () use ($factory) {
    $el = $factory->make();
    $messages = [
        ['role' => 'assistant', 'content' => 'hello'],
    ];

    $result = $el->evaluate('has_tool_calls(messages)', ['messages' => $messages]);

    expect($result)->toBeFalse();
});

it('has_tool_calls returns false for empty messages', function () use ($factory) {
    $el = $factory->make();

    $result = $el->evaluate('has_tool_calls(messages)', ['messages' => []]);

    expect($result)->toBeFalse();
});

it('send_all returns Send objects for each item', function () use ($factory) {
    $el = $factory->make();

    $result = $el->evaluate(
        "send_all('worker', state['urls'], 'url')",
        ['state' => ['urls' => ['http://a.com', 'http://b.com']]],
    );

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(Send::class);
    expect($result[0]->nodeName)->toBe('worker');
    expect($result[0]->payload)->toBe(['url' => 'http://a.com']);
    expect($result[1]->payload)->toBe(['url' => 'http://b.com']);
});

it('send_all returns empty array for empty items', function () use ($factory) {
    $el = $factory->make();

    $result = $el->evaluate(
        "send_all('worker', state['urls'], 'url')",
        ['state' => ['urls' => []]],
    );

    expect($result)->toBe([]);
});
