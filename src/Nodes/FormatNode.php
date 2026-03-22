<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

/**
 * A pure state-transform node. Accepts a Closure that receives the current
 * state and returns a mutation array.
 *
 * Usage:
 *   $workflow->addNode('format', new FormatNode(
 *       fn (array $state) => ['summary' => implode("\n", $state['lines'])]
 *   ));
 */
readonly class FormatNode implements Node
{
    public function __construct(private \Closure $transform)
    {
    }

    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ($this->transform)($state, $context->isolatedPayload);
    }
}
