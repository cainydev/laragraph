<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

/**
 * Keeps only the last N messages in a state list key (sliding window).
 * Prevents the state column from growing unboundedly in long-running agentic loops.
 *
 * Usage:
 *   $this->addNode('trim', new TrimMessagesNode(keep: 20));
 *   $this->addNode('trim', new TrimMessagesNode(keep: 20, key: 'history'));
 */
final class TrimMessagesNode implements Node
{
    public function __construct(
        public readonly int $keep = 20,
        public readonly string $key = 'messages',
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $messages = $state[$this->key] ?? [];

        if (! is_array($messages) || count($messages) <= $this->keep) {
            return [];
        }

        return [$this->key => array_values(array_slice($messages, -$this->keep))];
    }
}
