<?php

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Integrations\Prism\PrismNode;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;

use function Cainy\Laragraph\Tests\makeContext;

it('produces a text-mode assistant message from a Prism response', function () {
    Prism::fake([
        TextResponseFake::make()->withText('Hello world'),
    ]);

    $node = new PrismNode(systemPrompt: 'You are a test bot.');

    $mutation = $node->handle(makeContext(), ['messages' => []]);

    expect($mutation)->toHaveKey('messages');
    expect($mutation['messages'])->toHaveCount(1);
    expect($mutation['messages'][0]['content'])->toBe('Hello world');
});

it('writes structured output to state when schema() is overridden', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['name' => 'Laragraph', 'rating' => 5]),
    ]);

    $node = new class extends PrismNode
    {
        protected function schema(): ?Schema
        {
            return new ObjectSchema(
                name: 'review',
                description: 'A review',
                properties: [
                    new StringSchema('name', 'name'),
                    new StringSchema('rating', 'rating'),
                ],
                requiredFields: ['name', 'rating'],
            );
        }

        protected function prompt(array $state): string
        {
            return 'Review the package';
        }
    };

    $mutation = $node->handle(makeContext(), []);

    expect($mutation)->toHaveKey('output');
    expect($mutation['output'])->toBe(['name' => 'Laragraph', 'rating' => 5]);
    // No messages key in structured mode by default.
    expect($mutation)->not->toHaveKey('messages');
});

it('honours a custom outputKey() when writing structured output', function () {
    Prism::fake([
        StructuredResponseFake::make()->withStructured(['score' => 9]),
    ]);

    $node = new class extends PrismNode
    {
        protected function schema(): ?Schema
        {
            return new ObjectSchema('score', 'score', [new StringSchema('score', 'score')], ['score']);
        }

        protected function prompt(array $state): string
        {
            return 'Score it';
        }

        public function outputKey(): string
        {
            return 'analysis';
        }
    };

    $mutation = $node->handle(makeContext(), []);

    expect($mutation)->toHaveKey('analysis');
    expect($mutation['analysis'])->toBe(['score' => 9]);
});

it('PrismNode does not implement HasLoop', function () {
    $node = new PrismNode;
    expect($node)->not->toBeInstanceOf(HasLoop::class);
});
