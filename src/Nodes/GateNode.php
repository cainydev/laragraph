<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;

/**
 * Human-in-the-loop pause node. Pauses the workflow until manually resumed.
 */
final class GateNode implements SerializableNode
{
    public function __construct(
        public readonly string $reason = 'Approval required',
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        throw new NodePausedException(
            nodeName: $context->nodeName,
            stateMutation: ['gate_reason' => $this->reason],
        );
    }

    public function toArray(): array
    {
        return [
            '__synthetic' => 'gate',
            'reason' => $this->reason,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            reason: $data['reason'] ?? 'Approval required',
        );
    }
}
