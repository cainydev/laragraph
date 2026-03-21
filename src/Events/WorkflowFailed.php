<?php

namespace Cainy\Laragraph\Events;

class WorkflowFailed
{
    public function __construct(
        public readonly int $runId,
        public readonly \Throwable $exception,
    ) {}
}
