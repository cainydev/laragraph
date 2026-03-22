<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class SummarizeNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        $messages = $state['messages'] ?? [];
        $toolResult = '';

        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'tool') {
                $toolResult = $message['content'] ?? '';
                break;
            }
        }

        return ['summary' => $toolResult ?: 'No tool result found.'];
    }
}
