<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class WorkflowFailed implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(
        public readonly int $runId,
        public readonly \Throwable $exception,
        public readonly string $workflowKey = '',
    ) {}

    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'workflowKey' => $this->workflowKey,
            'exceptionMessage' => $this->exception->getMessage(),
        ];
    }
}
