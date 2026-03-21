<?php

namespace Cainy\Laragraph\Nodes;

abstract class ToolNode extends BaseNode
{
    /**
     * @return array<string, callable>
     */
    abstract protected function toolMap(): array;

    public function __invoke(int $runId, array $state): array
    {
        $pendingCalls = $state['pending_tool_calls'] ?? [];
        $map = $this->toolMap();
        $results = [];

        foreach ($pendingCalls as $call) {
            $name = $call['name'] ?? '';
            $arguments = $call['arguments'] ?? [];
            $id = $call['id'] ?? null;

            if (isset($map[$name])) {
                $output = ($map[$name])($arguments, $state);
            } else {
                $output = "Tool [{$name}] not found.";
            }

            $results[] = [
                'role'        => 'tool',
                'tool_use_id' => $id,
                'content'     => (string) $output,
            ];
        }

        return [
            'messages'           => $results,
            'pending_tool_calls' => [],
        ];
    }
}
