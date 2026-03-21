<?php

namespace Cainy\Laragraph\Events;

class WorkflowStarted
{
    public function __construct(
        public readonly int $runId,
        public readonly string $workflowName,
    ) {}
}
