<?php

namespace Cainy\Laragraph\Contracts;

use Cainy\Laragraph\Engine\NodeExecutionContext;

interface Node
{
    /**
     * Execute the node and return the state mutations.
     *
     * @param  NodeExecutionContext  $context  Execution context including run ID, attempt, node name, and isolated payload
     * @param  array  $state  The current, complete state of the graph
     * @return array The associative array of state mutations
     */
    public function handle(NodeExecutionContext $context, array $state): array;
}
