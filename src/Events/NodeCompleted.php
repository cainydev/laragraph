<?php

namespace Cainy\Laragraph\Events;

use Cainy\Laragraph\Events\Concerns\BroadcastsOnWorkflowChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NodeCompleted implements ShouldBroadcast
{
    use BroadcastsOnWorkflowChannel;

    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly array $mutation,
        public readonly array $tags = [],
    ) {}

    public function broadcastWith(): array
    {
        return [
            'runId' => $this->runId,
            'nodeName' => $this->nodeName,
            'mutation' => $this->mutation,
            'tags' => $this->tags,
        ];
    }
}
