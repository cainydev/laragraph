<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class HumanInterventionRequired implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(public readonly int $runId) {}

    public function broadcastWith(): array
    {
        return ['runId' => $this->runId];
    }
}
