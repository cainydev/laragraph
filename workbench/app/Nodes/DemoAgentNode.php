<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class DemoAgentNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(500_000); // Simulate LLM latency

        return [
            'messages' => [
                [
                    'role'       => 'assistant',
                    'content'    => 'Let me check the weather in London for you.',
                    'tool_calls' => [
                        ['id' => 'call_sim_001', 'name' => 'get_weather', 'arguments' => ['city' => 'London']],
                    ],
                ],
            ],
        ];
    }
}
