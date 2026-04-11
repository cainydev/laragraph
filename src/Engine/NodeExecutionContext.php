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
