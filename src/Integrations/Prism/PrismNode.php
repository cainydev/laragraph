<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

class PrismNode implements HasLoop, Node
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

    // -------------------------------------------------------------------------
    // HasLoop implementation
    // -------------------------------------------------------------------------

    public function loopNode(string $nodeName): Node
    {
        return new ToolExecutor(
            parentNodeName: $nodeName,
            parentNodeClass: static::class,
        );
    }

    public function loopCondition(): string|\Closure
    {
        return 'not_empty(last(state["messages"])["tool_calls"] ?? [])';
    }
}
