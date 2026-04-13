<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class HumanInterventionRequired implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly ?string $reason = null,
    ) {}

    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'nodeName' => $this->nodeName,
            'reason' => $this->reason,
        ];
    }
}
