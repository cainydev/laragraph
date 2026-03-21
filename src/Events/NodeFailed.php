<?php

namespace Cainy\Laragraph\Events;

class NodeFailed
{
    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly \Throwable $exception,
    ) {}
}
