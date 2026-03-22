<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class LinearNodeB implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['log' => ['node-b processed at ' . now()->toISOString()]];
    }
}
