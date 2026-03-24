<?php

namespace Workbench\App\Nodes\DeepResearch;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Routing\Send;

class PlannerNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(300_000); // Simulate LLM latency

        // Simulate extracting sub-queries from the user prompt
        $topic = $state['topic'] ?? 'artificial intelligence';
        $queries = [
            "Latest breakthroughs in {$topic} research 2024",
            "Real-world applications of {$topic} in industry",
            "Ethical concerns and limitations of {$topic}",
        ];

        // Return the queries; the branch edge will fan out via Send
        return [
            'queries' => $queries,
            'messages' => [
                ['type' => 'assistant', 'content' => implode("\n", $queries), 'tool_calls' => [], 'additional_content' => []],
            ],
        ];
    }
}
