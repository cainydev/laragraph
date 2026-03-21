<?php

namespace Cainy\Laragraph\Events;

class WorkflowCompleted
{
    public function __construct(
        public readonly int $runId,
    ) {}
}
