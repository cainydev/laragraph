<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class SummarizeNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        $messages = $state['messages'] ?? [];
        $result = '';

        foreach (array_reverse($messages) as $message) {
            $type = $message['type'] ?? '';

            if ($type === 'tool_result' && ! empty($message['tool_results'])) {
                $result = $message['tool_results'][0]['result'] ?? '';
                break;
            }

            // Final assistant message (from agent after tool loop)
            if ($type === 'assistant' && ! empty($message['content']) && empty($message['tool_calls'])) {
                $result = $message['content'];
                break;
            }
        }

        return ['summary' => $result ?: 'No tool result found.'];
    }
}
