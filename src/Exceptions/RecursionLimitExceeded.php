<?php

namespace Cainy\Laragraph\Exceptions;

use RuntimeException;

class RecursionLimitExceeded extends RuntimeException
{
    public function __construct(int $runId, int $limit)
    {
        parent::__construct(
            "Workflow run [{$runId}] exceeded the recursion limit of {$limit} node executions."
        );
    }
}
