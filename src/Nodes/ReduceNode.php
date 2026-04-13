<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodeSkippedException;

/**
 * Fan-in barrier node — waits until a required number of items have accumulated
 * in a state key before allowing execution to continue.
 */
final class ReduceNode implements Node
{
    public function __construct(
        public readonly string $collectKey,         // state key where results accumulate
        public readonly int $expectedCount = 0,     // static expected count (0 = use countFromKey)
        public readonly ?string $countFromKey = null, // OR: read expected count from state key
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        // Skip if other fan-in arrivals are still pending — only the last arrival
        // (pendingCount === 1) proceeds to evaluate edges and dispatch the next node.
        if ($context->pendingCount > 1) {
            throw new NodeSkippedException($context->nodeName);
        }

        $collected = $state[$this->collectKey] ?? [];
        $actualCount = is_array($collected) ? count($collected) : 0;

        $expected = $this->expectedCount > 0
            ? $this->expectedCount
            : (int) ($state[$this->countFromKey ?? ''] ?? 0);

        if ($actualCount < $expected) {
            throw new NodeSkippedException($context->nodeName);
        }

        return [];
    }
}
