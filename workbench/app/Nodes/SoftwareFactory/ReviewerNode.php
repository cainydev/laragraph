<?php

namespace Workbench\App\Nodes\SoftwareFactory;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class ReviewerNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(400_000); // Simulate LLM latency

        $code   = $state['code'] ?? '(no code)';
        $review = "Code review: The implementation is correct for small values of n. "
                . "Note: this recursive implementation has O(2^n) time complexity. "
                . "Consider memoization for production use.";

        return [
            'review'   => $review,
            'messages' => [
                ['role' => 'assistant', 'content' => $review],
            ],
        ];
    }
}
