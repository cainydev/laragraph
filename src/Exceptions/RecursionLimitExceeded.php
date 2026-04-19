<?php

namespace Cainy\Laragraph\Exceptions;

use RuntimeException;

class RecursionLimitExceeded extends RuntimeException
{
    public function __construct(int $runId, int $limit)
    {
        parent::__construct(
            "Workflow run [{$runId}] exceeded the recursion limit of {$limit} node executions. "
            .'If this is legitimate fan-out (many items through the same nodes), call '
            .'->withRecursionLimit(n) on the workflow or raise config(\'laragraph.recursion_limit\').'
        );
    }
}
