<?php

namespace Cainy\Laragraph\Events\Concerns;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;

trait BroadcastsOnWorkflowChannel
{
    public function broadcastOn(): array
    {
        $prefix = config('laragraph.broadcasting.channel_prefix', 'workflow.');
        $name = $prefix.$this->runId;

        return match (config('laragraph.broadcasting.channel_type', 'private')) {
            'public' => [new Channel($name)],
            'presence' => [new PresenceChannel($name)],
            default => [new PrivateChannel($name)],
        };
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('laragraph.broadcasting.enabled', false);
    }
}
