<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

abstract class ToolNode implements Node
{
    /**
     * @return array<string, callable>
     */
    abstract protected function toolMap(): array;

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $messages = $state['messages'] ?? [];
        $lastMessage = ! empty($messages) ? end($messages) : null;

        if ($lastMessage === null) {
            return [];
        }

        $toolCalls = $lastMessage['tool_calls'] ?? [];

        if (empty($toolCalls)) {
            return [];
        }

        $map = $this->toolMap();
        $toolResults = [];

        foreach ($toolCalls as $call) {
            $name = $call['name'] ?? '';
            $arguments = $call['arguments'] ?? [];
            $id = $call['id'] ?? null;

            if (! isset($map[$name])) {
                $output = "Tool [{$name}] not found.";
            } else {
                try {
                    $output = ($map[$name])($arguments, $state);
                } catch (\Throwable $e) {
                    $output = "Error executing tool '{$name}': {$e->getMessage()}. Please fix the arguments and try again.";
                }
            }

            $toolResults[] = [
                'tool_call_id' => $id,
                'tool_name' => $name,
                'args' => $arguments,
                'result' => (string) $output,
            ];
        }

        return ['messages' => [
            [
                'type' => 'tool_result',
                'tool_results' => $toolResults,
            ],
        ]];
    }
}
