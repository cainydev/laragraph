<?php

namespace Cainy\Laragraph\Exceptions;

use RuntimeException;

class UnroutableStateException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeName,
        public readonly int $runId,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?: "No outgoing edge matched for node [{$nodeName}] on run [{$runId}].", $code, $previous);
    }
}
