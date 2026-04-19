<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Models\WorkflowRun;
use DateTimeImmutable;

/**
 * The typed context passed to Node::handle(). Built per-execution from the
 * WorkflowRun row; prefer these accessors over reading WorkflowRun directly.
 *
 * - workflowKey: canonical runtime accessor; equals WorkflowRun::$key (the
 *   workflow class FQCN). Use this inside nodes.
 * - parentRunId / parentNodeName: set when this run was dispatched as a child
 *   workflow. parentMetadata() lazy-loads the parent row's metadata.
 * - routing: engine-managed routing metadata snapshot (read-only). Nodes
 *   should not mutate this — it is exposed so generic nodes like Workflow
 *   sub-graph dispatch can read their child_runs entry.
 */
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
        public ?int $parentRunId = null,
        public ?string $parentNodeName = null,
        /** @var array<string,mixed> Engine-managed routing metadata (read-only). */
        public array $routing = [],
    ) {}

    /**
     * Load the parent run's metadata (if this run has a parent). Returns null
     * when the run has no parent or the parent row is missing.
     *
     * @return array<string,mixed>|null
     */
    public function parentMetadata(): ?array
    {
        if ($this->parentRunId === null) {
            return null;
        }

        /** @var WorkflowRun|null $parent */
        $parent = WorkflowRun::find($this->parentRunId);

        return $parent?->metadata;
    }

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
            parentRunId: $run->parent_run_id,
            parentNodeName: $run->parent_node_name,
            routing: $run->routing ?? [],
        );
    }
}
