<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Structured\PendingRequest as PendingStructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\PendingRequest as PendingTextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

/**
 * General-purpose Prism base node.
 *
 * Supports three usage shapes out of the box:
 *   1. Text via constructor — pass provider/model/system-prompt/tools/maxTokens.
 *   2. Subclass with overrides — override provider(), model(), systemPrompt(),
 *      tools(), maxTokens(), temperature(), topP(), messages(), or request()
 *      for request-level tweaks (e.g. withProviderOptions).
 *   3. Structured output — override schema() to return a Prism Schema. The
 *      node will call asStructured() and write the structured payload to
 *      $state[outputKey()] alongside the assistant message.
 *
 * This node does NOT implement HasLoop. For tool-calling agents that re-enter
 * on tool results, extend PrismToolNode instead.
 */
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
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $schema = $this->schema();

        if ($schema !== null) {
            return $this->handleStructured($context, $state, $schema);
        }

        return $this->handleText($context, $state);
    }

    protected function handleText(NodeExecutionContext $context, array $state): array
    {
        $request = $this->buildTextRequest($context, $state);
        $response = $request->asText();

        return $this->mapTextResponse($response, $state);
    }

    protected function handleStructured(NodeExecutionContext $context, array $state, Schema $schema): array
    {
        $request = $this->buildStructuredRequest($context, $state, $schema);
        $response = $request->asStructured();

        return $this->mapStructuredResponse($response, $state);
    }

    protected function buildTextRequest(NodeExecutionContext $context, array $state): PendingTextRequest
    {
        $request = app(Prism::class)->text()
            ->using($this->provider(), $this->model())
            ->withMaxTokens($this->maxTokens());

        $system = $this->systemPrompt($state);
        if ($system !== '') {
            $request = $request->withSystemPrompt($system);
        }

        $temperature = $this->temperature();
        if ($temperature !== null) {
            $request = $request->usingTemperature($temperature);
        }

        $topP = $this->topP();
        if ($topP !== null) {
            $request = $request->usingTopP($topP);
        }

        $messages = $this->messages($state);
        if (! empty($messages)) {
            $request = $request->withMessages(MessageSerializer::hydrate($messages));
        } else {
            $prompt = $this->prompt($state);
            if ($prompt !== '') {
                $request = $request->withPrompt($prompt);
            }
        }

        $tools = $this->tools();
        if (! empty($tools)) {
            $request = $request->withTools($tools);
        }

        return $this->applyProviderOptions($request, $state);
    }

    protected function buildStructuredRequest(NodeExecutionContext $context, array $state, Schema $schema): PendingStructuredRequest
    {
        $request = app(Prism::class)->structured()
            ->using($this->provider(), $this->model())
            ->withSchema($schema)
            ->withMaxTokens($this->maxTokens());

        $system = $this->systemPrompt($state);
        if ($system !== '') {
            $request = $request->withSystemPrompt($system);
        }

        $temperature = $this->temperature();
        if ($temperature !== null) {
            $request = $request->usingTemperature($temperature);
        }

        $messages = $this->messages($state);
        if (! empty($messages)) {
            $request = $request->withMessages(MessageSerializer::hydrate($messages));
        } else {
            $prompt = $this->prompt($state);
            if ($prompt !== '') {
                $request = $request->withPrompt($prompt);
            }
        }

        return $this->applyProviderOptions($request, $state);
    }

    /**
     * Override to call withProviderOptions() / withClientOptions() on the request.
     *
     * @template T of PendingTextRequest|PendingStructuredRequest
     *
     * @param  T  $request
     * @return T
     */
    protected function applyProviderOptions(PendingTextRequest|PendingStructuredRequest $request, array $state): PendingTextRequest|PendingStructuredRequest
    {
        return $request;
    }

    /**
     * Map a text response to a state mutation. Default: append an AssistantMessage
     * to $state['messages'] at the configured messages key.
     */
    protected function mapTextResponse(TextResponse $response, array $state): array
    {
        // Go through MessageSerializer so we don't depend on AssistantMessage::toArray()
        // (not present in prism-php/prism v0.99.0 — only added later in the 0.99.x line).
        $dehydrated = MessageSerializer::dehydrate([
            new AssistantMessage(
                content: $response->text,
                toolCalls: $response->toolCalls,
                additionalContent: $response->additionalContent,
            ),
        ]);

        return [
            $this->messagesKey() => $dehydrated,
        ];
    }

    /**
     * Map a structured response to a state mutation. Default: writes the
     * structured array to $state[outputKey()].
     */
    protected function mapStructuredResponse(StructuredResponse $response, array $state): array
    {
        return [
            $this->outputKey() => $response->structured,
        ];
    }

    // ─── Overridable hooks ───────────────────────────────────────────────────

    /**
     * Return a Prism Schema to enable structured output. Default null = text mode.
     */
    protected function schema(): ?Schema
    {
        return null;
    }

    protected function provider(): Provider|string
    {
        return $this->provider;
    }

    protected function model(): string
    {
        return $this->model;
    }

    protected function maxTokens(): int
    {
        return $this->maxTokens;
    }

    protected function temperature(): ?float
    {
        return null;
    }

    protected function topP(): ?float
    {
        return null;
    }

    protected function systemPrompt(array $state): string
    {
        return $this->systemPrompt;
    }

    /**
     * Override to provide an ad-hoc user prompt when no messages are set.
     * Ignored when messages() returns non-empty.
     */
    protected function prompt(array $state): string
    {
        return '';
    }

    /**
     * Conversation history (raw arrays; will be hydrated via MessageSerializer).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function messages(array $state): array
    {
        return $state[$this->messagesKey()] ?? [];
    }

    /**
     * @return array<Tool>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * The state key where assistant messages are appended in text mode.
     */
    public function messagesKey(): string
    {
        return 'messages';
    }

    /**
     * The state key where structured output is written. Ignored in text mode.
     */
    public function outputKey(): string
    {
        return 'output';
    }
}
