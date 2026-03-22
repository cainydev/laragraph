<?php

namespace Workbench\App\Nodes\DeepResearch;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class ResearchWorkerNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(400_000); // Simulate web search latency

        $query = $isolatedPayload['search_query'] ?? 'unknown query';

        return [
            'findings' => [
                "Research result for: \"{$query}\" — Found 3 relevant sources with positive sentiment and strong evidence base.",
            ],
        ];
    }
}
