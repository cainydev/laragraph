<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Tool;

class DemoAgentNode implements Node
{
    public int $maxIterations = 25;

    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(500_000); // Simulate LLM latency

        $messages = $state['messages'] ?? [];
        $lastMessage = ! empty($messages) ? end($messages) : null;

        // If the last message is a tool result, we've already called tools — summarize
        if ($lastMessage !== null && ($lastMessage['type'] ?? '') === 'tool_result') {
            return [
                'messages' => [
                    [
                        'type' => 'assistant',
                        'content' => 'The weather in London is sunny at 22°C.',
                        'tool_calls' => [],
                        'additional_content' => [],
                    ],
                ],
                "__{$context->nodeName}_iterations" => 0,
            ];
        }

        // First call — request a tool call
        return [
            'messages' => [
                [
                    'type' => 'assistant',
                    'content' => 'Let me check the weather in London for you.',
                    'tool_calls' => [
                        ['id' => 'call_sim_001', 'name' => 'get_weather', 'arguments' => ['city' => 'London']],
                    ],
                    'additional_content' => [],
                ],
            ],
            "__{$context->nodeName}_iterations" => 0,
        ];
    }

    /**
     * @return array<Tool>
     */
    public function tools(): array
    {
        return [
            (new Tool)
                ->as('get_weather')
                ->for('Get the current weather for a city')
                ->withStringParameter('city', 'The city name')
                ->using(fn (string $city): string => 'Sunny, 22°C in ' . $city),
        ];
    }
}
