<?php

namespace Cainy\Laragraph\Integrations\LaravelAi;

use Cainy\Laragraph\Engine\NodeExecutionContext;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Allows a Laravel AI Agent to act seamlessly as a Laragraph Node.
 *
 * By using this trait, the Agent can be directly registered in a Laragraph
 * workflow. It hydrates the graph's current state and execution context into
 * the Agent's properties before execution, allowing native Laravel AI methods
 * (like instructions() or tools()) to react dynamically to the graph state.
 *
 * Must be used on a class that also uses \Laravel\Ai\Promptable.
 */
trait AsGraphNode
{
    /**
     * The current state of the workflow graph.
     * Populated immediately before the agent is executed.
     *
     * @var array<string, mixed>
     */
    public array $state = [];

    /**
     * The orchestration context for the current node execution.
     * Populated immediately before the agent is executed.
     *
     * @var NodeExecutionContext|null
     */
    public ?NodeExecutionContext $ctx = null;

    public int $maxIterations = 25;

    /**
     * Return the tools available to this agent for compile-time tool loop detection.
     *
     * If the implementing class also implements Laravel\Ai\Contracts\HasTools,
     * override this to delegate: return $this->tools();
     *
     * @return array
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Fulfill the Laragraph Node contract.
     *
     * @param NodeExecutionContext $ctx Orchestration metadata.
     * @param array<string, mixed> $state The current business state.
     * @return array<string, mixed> The state mutations to apply.
     */
    public function handle(NodeExecutionContext $ctx, array $state): array
    {
        // 1. Hydrate the class properties so the Agent can use them natively.
        $this->ctx = $ctx;
        $this->state = $state;

        // 2. Call Laravel AI's native prompt() execution.
        // Assumes the implementing class uses the \Laravel\Ai\Promptable trait.
        $response = $this->prompt($this->getAgentPrompt());

        // 3. Auto-convert Structured Output to a Graph State Mutation.
        if ($this instanceof HasStructuredOutput) {
            return $this->mutateStateWithStructuredOutput($response);
        }

        // 4. Fallback for plain-text agents (appends to conversation history).
        return $this->mutateStateWithTextOutput((string) $response);
    }

    /**
     * Define the prompt string passed to the AI.
     *
     * By default, this attempts to grab the last message in the state's
     * 'messages' array, falls back to a 'prompt' key, or passes 'Continue.'.
     * Override this method in your Agent to customize the prompt generation.
     */
    protected function getAgentPrompt(): string
    {
        $messages = $this->state['messages'] ?? [];
        $lastMessage = end($messages);

        return $lastMessage['content'] ?? $this->state['prompt'] ?? 'Continue.';
    }

    /**
     * Map the Agent's structured output back to a Laragraph state mutation.
     *
     * By default, this assumes your JSON Schema keys perfectly match your
     * graph state keys. Override this if you need to map nested arrays or
     * rename keys before merging.
     *
     * @param mixed $response The structured output response.
     * @return array<string, mixed>
     */
    protected function mutateStateWithStructuredOutput(mixed $response): array
    {
        return is_array($response) ? $response : $response->toArray();
    }

    /**
     * Map the Agent's raw text output back to a Laragraph state mutation.
     *
     * By default, this assumes a conversational graph and appends the
     * response to the 'messages' state array as an 'assistant' message.
     * It tags the message with the Agent's class name to track who spoke.
     *
     * @param string $response The raw text output from the LLM.
     * @return array<string, mixed>
     */
    protected function mutateStateWithTextOutput(string $response): array
    {
        $agentName = str()->snake(class_basename($this));

        return [
            'messages' => [
                [
                    'type' => 'assistant',
                    'content' => $response,
                    'tool_calls' => [],
                    'additional_content' => ['name' => $agentName],
                ],
            ],
        ];
    }
}
