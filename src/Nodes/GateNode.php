<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;

/**
 * Human-in-the-loop pause node. Pauses the workflow until manually resumed.
 */
final class GateNode implements Node
{
    public function __construct(
        public readonly string $reason = 'Approval required',
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        throw new NodePausedException(
            nodeName: $context->nodeName,
            gateReason: $this->reason,
        );
    }
}
