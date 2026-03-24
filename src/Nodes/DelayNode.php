<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;

/**
 * Delay node — pauses execution for a given number of seconds.
 *
 * On first execution it stores a resume-after timestamp in state and pauses.
 * On resume it checks if enough time has passed; if not it pauses again.
 */
final class DelayNode implements SerializableNode
{
    public function __construct(
        public readonly int $seconds = 60,
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $resumeKey = "__delay_resume_{$context->nodeName}";
        $resumeAt = $state[$resumeKey] ?? null;

        if ($resumeAt === null) {
            // First execution — schedule the resume timestamp and pause
            $resumeAt = now()->addSeconds($this->seconds)->timestamp;
            throw new NodePausedException(
                nodeName: $context->nodeName,
                stateMutation: [$resumeKey => $resumeAt],
            );
        }

        if (now()->timestamp < $resumeAt) {
            // Not yet time — pause again (caller should re-dispatch with a delay)
            throw new NodePausedException(nodeName: $context->nodeName);
        }

        // Delay complete — clean up the marker key
        return [$resumeKey => null];
    }

    public function toArray(): array
    {
        return [
            '__synthetic' => 'delay',
            'seconds' => $this->seconds,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            seconds: $data['seconds'] ?? 60,
        );
    }
}
