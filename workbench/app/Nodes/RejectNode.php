<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class RejectNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['rejected' => true];
    }
}
