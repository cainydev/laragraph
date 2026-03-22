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
    ) {}

    public static function fromJob(WorkflowRun $run, string $nodeName, int $attempt, int $maxAttempts, ?array $isolatedPayload = null): self
    {
        return new self(
            runId: $run->id,
            workflowKey: $run->key ?? '',
            nodeName: $nodeName,
            attempt: $attempt,
            maxAttempts: $maxAttempts,
            createdAt: $run->created_at->toDateTimeImmutable(),
            isolatedPayload: $isolatedPayload,
        );
    }
}
