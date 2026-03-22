<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class WorkflowStarted implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(
        public readonly int $runId,
        public readonly string $workflowName,
    ) {}

    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'workflowName' => $this->workflowName,
        ];
    }
}
