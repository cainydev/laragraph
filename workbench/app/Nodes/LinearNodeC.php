<?php

namespace Workbench\App\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class LinearNodeC implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return ['log' => ['node-c processed at '.now()->toISOString()]];
    }
}
