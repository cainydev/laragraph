<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;

abstract class AgentNode implements Node
{
    protected Provider|string $provider = Provider::Anthropic;

    protected string $model = 'claude-3-5-haiku-20241022';

    protected string $systemPrompt = '';

    protected int $maxTokens = 1024;

    public function handle(NodeExecutionContext $context, array $state): array
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

        $assistantMessage = ['role' => 'assistant', 'content' => $response->text];

        if (! empty($response->toolCalls)) {
            $assistantMessage['tool_calls'] = array_map(fn ($tc) => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $response->toolCalls);
        }

        return ['messages' => [$assistantMessage]];
    }

    protected function getPrompt(array $state): string
    {
        return '';
    }

    /**
     * @return array<Tool>
     */
    protected function tools(): array
    {
        return [];
    }
}
