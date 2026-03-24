<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;

/**
 * Fan-in barrier node — waits until a required number of items have accumulated
 * in a state key before allowing execution to continue.
 */
final class ReduceNode implements SerializableNode
{
    public function __construct(
        public readonly string $collectKey,         // state key where results accumulate
        public readonly int $expectedCount = 0,     // static expected count (0 = use countFromKey)
        public readonly ?string $countFromKey = null, // OR: read expected count from state key
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $collected = $state[$this->collectKey] ?? [];
        $actualCount = is_array($collected) ? count($collected) : 0;

        $expected = $this->expectedCount > 0
            ? $this->expectedCount
            : (int) ($state[$this->countFromKey ?? ''] ?? 0);

        if ($actualCount < $expected) {
            throw new NodePausedException(nodeName: $context->nodeName);
        }

        return [];
    }

    public function toArray(): array
    {
        return [
            '__synthetic' => 'reduce',
            'collect_key' => $this->collectKey,
            'expected_count' => $this->expectedCount,
            'count_from_key' => $this->countFromKey,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectKey: $data['collect_key'],
            expectedCount: $data['expected_count'] ?? 0,
            countFromKey: $data['count_from_key'] ?? null,
        );
    }
}
