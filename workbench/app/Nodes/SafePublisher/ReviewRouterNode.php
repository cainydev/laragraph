<?php

namespace Workbench\App\Nodes\SafePublisher;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

/**
 * Pass-through node: the branch edge on this node routes
 * based on state['meta']['approved'] set during resume.
 */
class ReviewRouterNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return [];
    }
}
