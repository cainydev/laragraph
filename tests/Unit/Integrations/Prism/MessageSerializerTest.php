<?php

use Cainy\Laragraph\Integrations\Prism\MessageSerializer;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('dehydrates and hydrates UserMessage roundtrip', function () {
    $original = [new UserMessage('Hello, world!')];

    $dehydrated = MessageSerializer::dehydrate($original);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(1);
    expect($hydrated[0])->toBeInstanceOf(UserMessage::class);
    expect($hydrated[0]->content)->toBe('Hello, world!');
});

it('dehydrates and hydrates SystemMessage roundtrip', function () {
    $original = [new SystemMessage('You are a helpful assistant.')];

    $dehydrated = MessageSerializer::dehydrate($original);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(1);
    expect($hydrated[0])->toBeInstanceOf(SystemMessage::class);
    expect($hydrated[0]->content)->toBe('You are a helpful assistant.');
});

it('dehydrates and hydrates AssistantMessage without tool calls', function () {
    $original = [new AssistantMessage('I can help with that.')];

    $dehydrated = MessageSerializer::dehydrate($original);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(1);
    expect($hydrated[0])->toBeInstanceOf(AssistantMessage::class);
    expect($hydrated[0]->content)->toBe('I can help with that.');
    expect($hydrated[0]->toolCalls)->toBeEmpty();
});

it('dehydrates and hydrates AssistantMessage with tool calls', function () {
    $toolCall = new ToolCall(id: 'tc1', name: 'get_weather', arguments: ['city' => 'London']);
    $original = [new AssistantMessage('Let me check.', [$toolCall])];

    $dehydrated = MessageSerializer::dehydrate($original);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(1);
    expect($hydrated[0])->toBeInstanceOf(AssistantMessage::class);
    expect($hydrated[0]->toolCalls)->toHaveCount(1);
    expect($hydrated[0]->toolCalls[0]->id)->toBe('tc1');
    expect($hydrated[0]->toolCalls[0]->name)->toBe('get_weather');
    expect($hydrated[0]->toolCalls[0]->arguments())->toBe(['city' => 'London']);
});

it('dehydrates and hydrates ToolResultMessage roundtrip', function () {
    $toolResult = new ToolResult(
        toolCallId: 'tc1',
        toolName: 'get_weather',
        args: ['city' => 'London'],
        result: 'Sunny, 22°C',
    );
    $original = [new ToolResultMessage([$toolResult])];

    $dehydrated = MessageSerializer::dehydrate($original);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(1);
    expect($hydrated[0])->toBeInstanceOf(ToolResultMessage::class);
    expect($hydrated[0]->toolResults)->toHaveCount(1);
    expect($hydrated[0]->toolResults[0]->toolCallId)->toBe('tc1');
    expect($hydrated[0]->toolResults[0]->toolName)->toBe('get_weather');
    expect($hydrated[0]->toolResults[0]->result)->toBe('Sunny, 22°C');
});

it('hydrates legacy role=assistant format', function () {
    $data = [
        ['role' => 'assistant', 'content' => 'Hello'],
    ];

    $hydrated = MessageSerializer::hydrate($data);

    expect($hydrated[0])->toBeInstanceOf(AssistantMessage::class);
    expect($hydrated[0]->content)->toBe('Hello');
});

it('hydrates legacy role=user format', function () {
    $data = [
        ['role' => 'user', 'content' => 'Hi there'],
    ];

    $hydrated = MessageSerializer::hydrate($data);

    expect($hydrated[0])->toBeInstanceOf(UserMessage::class);
    expect($hydrated[0]->content)->toBe('Hi there');
});

it('hydrates legacy role=tool format', function () {
    $data = [
        ['role' => 'tool', 'tool_use_id' => 'tc1', 'name' => 'get_weather', 'content' => 'Sunny'],
    ];

    $hydrated = MessageSerializer::hydrate($data);

    expect($hydrated[0])->toBeInstanceOf(ToolResultMessage::class);
    expect($hydrated[0]->toolResults)->toHaveCount(1);
    expect($hydrated[0]->toolResults[0]->toolCallId)->toBe('tc1');
    expect($hydrated[0]->toolResults[0]->result)->toBe('Sunny');
});

it('throws on unknown message type', function () {
    MessageSerializer::hydrate([['type' => 'banana', 'content' => 'hi']]);
})->throws(InvalidArgumentException::class, 'Unknown message type [banana]');

it('handles multiple messages in sequence', function () {
    $messages = [
        new UserMessage('What is the weather?'),
        new AssistantMessage('Let me check.', [
            new ToolCall(id: 'tc1', name: 'weather', arguments: ['city' => 'NYC']),
        ]),
        new ToolResultMessage([
            new ToolResult(toolCallId: 'tc1', toolName: 'weather', args: ['city' => 'NYC'], result: 'Rainy'),
        ]),
        new AssistantMessage('It is rainy in NYC.'),
    ];

    $dehydrated = MessageSerializer::dehydrate($messages);
    $hydrated = MessageSerializer::hydrate($dehydrated);

    expect($hydrated)->toHaveCount(4);
    expect($hydrated[0])->toBeInstanceOf(UserMessage::class);
    expect($hydrated[1])->toBeInstanceOf(AssistantMessage::class);
    expect($hydrated[1]->toolCalls)->toHaveCount(1);
    expect($hydrated[2])->toBeInstanceOf(ToolResultMessage::class);
    expect($hydrated[3])->toBeInstanceOf(AssistantMessage::class);
    expect($hydrated[3]->content)->toBe('It is rainy in NYC.');
});
