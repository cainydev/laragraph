<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class FlakyNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        // Use context->attempt for real queue retries; fall back to state for sync-queue tests.
        $attempt = isset($state['attempt']) ? $state['attempt'] : $context->attempt;

        if ($attempt < 1) {
            throw new \RuntimeException("Flaky node failed on attempt {$attempt}. Will retry.");
        }

        return [
            'attempt' => $attempt,
            'recovered' => true,
        ];
    }
}
