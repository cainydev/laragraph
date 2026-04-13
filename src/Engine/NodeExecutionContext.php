<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Models\WorkflowRun;
use DateTimeImmutable;

readonly class NodeExecutionContext
{
    public function __construct(
        public int $runId,
        public string $workflowKey,
        public string $nodeName,
        public int $attempt,
        public int $maxAttempts,
        public DateTimeImmutable $createdAt,
        public ?array $isolatedPayload = null,
        public int $pendingCount = 1,
    ) {}

    /**
     * Returns true when this node was dispatched via a Send object (fan-out execution).
     * The isolated payload is available via payload().
     */
    public function isSendExecution(): bool
    {
        return $this->isolatedPayload !== null;
    }

    /**
     * Retrieve a value from the isolated Send payload by key.
     * Returns $default when the node was not dispatched via Send or the key is absent.
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        return $this->isolatedPayload[$key] ?? $default;
    }

    public static function fromJob(WorkflowRun $run, string $nodeName, int $attempt, int $maxAttempts, ?array $isolatedPayload = null): self
    {
        $pendingCount = count(array_filter(
            $run->active_pointers ?? [],
            fn (string $p) => $p === $nodeName,
        ));

        return new self(
            runId: $run->id,
            workflowKey: $run->key ?? '',
            nodeName: $nodeName,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            createdAt: $run->created_at->toDateTimeImmutable(),
            isolatedPayload: $isolatedPayload,
            pendingCount: max(1, $pendingCount),
        );
    }
}
