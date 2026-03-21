<?php

namespace Cainy\Laragraph\Exceptions;

use RuntimeException;

class NodeExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeName,
        public readonly int $runId,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "Node [{$nodeName}] failed on run [{$runId}].", $code, $previous);
    }
}
