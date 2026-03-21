<?php

namespace Cainy\Laragraph\Nodes;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

abstract class AgentNode extends BaseNode
{
    protected Provider|string $provider = Provider::Anthropic;

    protected string $model = 'claude-3-5-haiku-20241022';

    protected string $systemPrompt = '';

    protected int $maxTokens = 1024;

    public function __invoke(int $runId, array $state): array
    {
        $messages = $state['messages'] ?? [];

        $request = Prism::text()
            ->using($this->provider, $this->model)
            ->withMaxTokens($this->maxTokens);

        if ($this->systemPrompt !== '') {
            $request = $request->withSystemPrompt($this->systemPrompt);
        }

        if (! empty($messages)) {
            $request = $request->withMessages($messages);
        } else {
            $request = $request->withPrompt($this->getPrompt($state));
        }

        $tools = $this->tools();
        if (! empty($tools)) {
            $request = $request->withTools($tools);
        }

        $response = $request->asText();

        $assistantMessage = [
            'role'    => 'assistant',
            'content' => $response->text,
        ];

        if (! empty($response->toolCalls)) {
            return [
                'messages'           => [$assistantMessage],
                'pending_tool_calls' => array_map(fn ($tc) => [
                    'id'        => $tc->id,
                    'name'      => $tc->name,
                    'arguments' => $tc->arguments(),
                ], $response->toolCalls),
            ];
        }

        return [
            'messages'           => [$assistantMessage],
            'pending_tool_calls' => [],
        ];
    }

    protected function getPrompt(array $state): string
    {
        return '';
    }

    /**
     * @return array<\Prism\Prism\Tool>
     */
    protected function tools(): array
    {
        return [];
    }
}
