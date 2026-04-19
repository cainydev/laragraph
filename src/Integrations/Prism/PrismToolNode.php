<?php

namespace Cainy\Laragraph\Integrations\Prism;

use Cainy\Laragraph\Contracts\HasLoop;
use Cainy\Laragraph\Contracts\Node;

/**
 * Tool-calling variant of PrismNode. Implements HasLoop so the engine injects
 * a synthetic `.__loop__` tool-executor node and routes back after each tool
 * invocation — the canonical ReAct-style agent pattern.
 *
 * Use PrismNode (without HasLoop) when you do not want the tool-execution
 * loop, e.g. for plain text or structured-output calls.
 */
class PrismToolNode extends PrismNode implements HasLoop
{
    public function loopNode(string $nodeName): Node
    {
        return new ToolExecutor(
            parentNodeName: $nodeName,
            parentNodeClass: static::class,
        );
    }

    public function loopCondition(): \Closure
    {
        $messagesKey = $this->messagesKey();

        return function (array $state) use ($messagesKey): bool {
            $messages = $state[$messagesKey] ?? [];
            $last = ! empty($messages) ? end($messages) : null;

            return ! empty($last['tool_calls'] ?? []);
        };
    }
}
