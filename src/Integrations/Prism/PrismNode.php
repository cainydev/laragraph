<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;

class PrismNode implements Node
{
    /**
     * @param  array<Tool>  $tools
     */
    public function __construct(
        protected Provider|string $provider = Provider::Anthropic,
        protected string $model = 'claude-sonnet-4-20250514',
        protected string $systemPrompt = '',
        protected int $maxTokens = 1024,
        protected array $tools = [],
        public int $maxIterations = 25,
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $messages = $state['messages'] ?? [];

        $request = app(Prism::class)->text()
            ->using($this->provider, $this->model)
            ->withMaxTokens($this->maxTokens);

        if ($this->systemPrompt !== '') {
            $request = $request->withSystemPrompt($this->systemPrompt);
        }

        if (! empty($messages)) {
            $request = $request->withMessages(MessageSerializer::hydrate($messages));
        } else {
            $prompt = $this->getPrompt($state);
            if ($prompt !== '') {
                $request = $request->withPrompt($prompt);
            }
        }

        $resolvedTools = $this->tools();
        if (! empty($resolvedTools)) {
            $request = $request->withTools($resolvedTools);
        }

        $response = $request->asText();

        $assistantMessage = (new AssistantMessage(
            content: $response->text,
            toolCalls: $response->toolCalls,
            additionalContent: $response->additionalContent,
        ))->toArray();

        return [
            'messages' => [$assistantMessage],
            "__{$context->nodeName}_iterations" => 0,
        ];
    }

    protected function getPrompt(array $state): string
    {
        return '';
    }

    /**
     * @return array<Tool>
     */
    public function tools(): array
    {
        return $this->tools;
    }
}
