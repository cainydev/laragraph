<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageSerializer
{
    /**
     * Convert Prism Message objects to plain arrays for JSON storage.
     *
     * @param  array<Message&Arrayable<string, mixed>>  $messages
     * @return array<array<string, mixed>>
     */
    public static function dehydrate(array $messages): array
    {
        return array_map(fn (Message&Arrayable $message): array => $message->toArray(), $messages);
    }

    /**
     * Convert plain arrays back to Prism Message objects.
     *
     * @param  array<array<string, mixed>>  $messages
     * @return array<Message>
     */
    public static function hydrate(array $messages): array
    {
        return array_map(self::hydrateMessage(...), $messages);
    }

    private static function hydrateMessage(array $data): Message
    {
        // Support both 'type' (Prism native) and 'role' (legacy) keys
        $type = $data['type'] ?? self::mapLegacyRole($data['role'] ?? null);

        return match ($type) {
            'user' => new UserMessage($data['content']),
            'assistant' => self::hydrateAssistantMessage($data),
            'tool_result' => self::hydrateToolResultMessage($data),
            'system' => new SystemMessage($data['content']),
            default => throw new \InvalidArgumentException("Unknown message type [{$type}]."),
        };
    }

    private static function mapLegacyRole(?string $role): ?string
    {
        return match ($role) {
            'assistant' => 'assistant',
            'user' => 'user',
            'tool' => 'tool_result',
            'system' => 'system',
            default => $role,
        };
    }

    private static function hydrateAssistantMessage(array $data): AssistantMessage
    {
        $toolCalls = array_map(
            fn (array $tc): ToolCall => new ToolCall(
                id: $tc['id'],
                name: $tc['name'],
                arguments: $tc['arguments'],
                resultId: $tc['result_id'] ?? null,
                reasoningId: $tc['reasoning_id'] ?? null,
                reasoningSummary: $tc['reasoning_summary'] ?? null,
            ),
            $data['tool_calls'] ?? [],
        );

        return new AssistantMessage(
            content: $data['content'] ?? '',
            toolCalls: $toolCalls,
            additionalContent: $data['additional_content'] ?? [],
        );
    }

    private static function hydrateToolResultMessage(array $data): ToolResultMessage
    {
        // Support both 'tool_results' (Prism native) and legacy single-result format
        $toolResultsData = $data['tool_results'] ?? [];

        // Legacy format: single tool result as flat keys
        if (empty($toolResultsData) && isset($data['tool_use_id'])) {
            $toolResultsData = [[
                'tool_call_id' => $data['tool_use_id'],
                'tool_name' => $data['name'] ?? '',
                'args' => [],
                'result' => $data['content'] ?? '',
            ]];
        }

        $toolResults = array_map(
            fn (array $tr): ToolResult => new ToolResult(
                toolCallId: $tr['tool_call_id'],
                toolName: $tr['tool_name'],
                args: $tr['args'] ?? [],
                result: $tr['result'],
                toolCallResultId: $tr['tool_call_result_id'] ?? null,
            ),
            $toolResultsData,
        );

        return new ToolResultMessage($toolResults);
    }
}
