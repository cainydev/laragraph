<?php

namespace Cainy\Laragraph\Events;

class NodeCompleted
{
    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly array $mutation,
    ) {}
}
