<?php

namespace Cainy\Laragraph\Events;

class NodeExecuting
{
    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
    ) {}
}
