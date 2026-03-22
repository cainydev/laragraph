<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class MergeNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        // Barrier: wait until both branches have written their results
        if (! isset($state['branch_a_result'], $state['branch_b_result'])) {
            return [];
        }

        return ['merged' => true];
    }
}
