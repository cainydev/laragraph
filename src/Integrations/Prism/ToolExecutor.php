<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\HasName;
use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;

final class ToolExecutor implements HasName, SerializableNode
{
    public function __construct(
        private readonly string $parentNodeName,
        private readonly string $parentNodeClass,
    ) {}

    public function name(): string
    {
        return $this->parentNodeName.'.tools';
    }

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $parent = app($this->parentNodeClass);
        $tools = $parent->tools();

        $messages = $state['messages'] ?? [];
        $lastMessage = ! empty($messages) ? end($messages) : null;

        if ($lastMessage === null || empty($lastMessage['tool_calls'])) {
            return [];
        }

        $toolMap = [];
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $toolMap[$tool->name()] = $tool;
            }
        }

        $toolResults = [];

        foreach ($lastMessage['tool_calls'] as $call) {
            $name = $call['name'] ?? '';
            $arguments = is_string($call['arguments'] ?? '') ? json_decode($call['arguments'], true) ?? [] : ($call['arguments'] ?? []);
            $id = $call['id'] ?? null;

            if (! isset($toolMap[$name])) {
                $toolResults[] = [
                    'tool_call_id' => $id,
                    'tool_name' => $name,
                    'args' => $arguments,
                    'result' => "Tool [{$name}] not found.",
                ];

                continue;
            }

            $result = $toolMap[$name]->handle(...$arguments);

            $output = $result instanceof ToolError
                ? "Error: {$result->message}"
                : (string) $result;

            $toolResults[] = [
                'tool_call_id' => $id,
                'tool_name' => $name,
                'args' => $arguments,
                'result' => $output,
            ];
        }

        return [
            'messages' => [[
                'type' => 'tool_result',
                'tool_results' => $toolResults,
            ]],
        ];
    }

    /**
     * @return array{__synthetic: string, parent_node_name: string, parent_node_class: string}
     */
    public function toArray(): array
    {
        return [
            '__synthetic' => 'tool_executor',
            'parent_node_name' => $this->parentNodeName,
            'parent_node_class' => $this->parentNodeClass,
        ];
    }

    /**
     * @param  array{parent_node_name: string, parent_node_class: string}  $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            parentNodeName: $data['parent_node_name'],
            parentNodeClass: $data['parent_node_class'],
        );
    }

    public function getParentNodeName(): string
    {
        return $this->parentNodeName;
    }

    public function getParentNodeClass(): string
    {
        return $this->parentNodeClass;
    }
}
