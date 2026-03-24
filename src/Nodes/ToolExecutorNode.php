<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\HasName;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;

class ToolExecutorNode implements HasName, Node
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
        $parent = $this->resolveParent();
        $tools = $parent->tools();
        $maxIterations = $this->resolveMaxIterations($parent);

        $messages = $state['messages'] ?? [];
        $lastMessage = ! empty($messages) ? end($messages) : null;

        if ($lastMessage === null || empty($lastMessage['tool_calls'])) {
            return [];
        }

        // Check iteration counter
        $counterKey = "__{$this->parentNodeName}_iterations";
        $count = (int) ($state[$counterKey] ?? 0);

        if ($count >= $maxIterations) {
            return [
                'messages' => [[
                    'type' => 'assistant',
                    'content' => "Maximum tool iterations ({$maxIterations}) reached.",
                    'tool_calls' => [],
                    'additional_content' => [],
                ]],
            ];
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
            $counterKey => $count + 1,
        ];
    }

    private function resolveParent(): object
    {
        return app($this->parentNodeClass);
    }

    private function resolveMaxIterations(object $parent): int
    {
        if (property_exists($parent, 'maxIterations')) {
            return (int) $parent->maxIterations;
        }

        if (method_exists($parent, 'maxIterations')) {
            return (int) $parent->maxIterations();
        }

        return 25;
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
    public static function fromArray(array $data): self
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
