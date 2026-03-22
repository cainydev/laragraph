<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NodeFailed implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly \Throwable $exception,
    ) {}

    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'nodeName' => $this->nodeName,
            'exceptionMessage' => $this->exception->getMessage(),
        ];
    }
}
