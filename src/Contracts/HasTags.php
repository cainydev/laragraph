<?php

namespace Cainy\Laragraph\Contracts;

/**
 * Allows a Node to emit contextual metadata for tracing and analytics.
 *
 * In AI workflows, tags are crucial for tracking token usage, identifying
 * which LLM models were used, or grouping executions by tenant/user ID.
 * These tags are broadcasted alongside the NodeCompleted event.
 */
interface HasTags
{
    /**
     * Get the tags that should be attached to the node's execution telemetry.
     *
     * @return array<string, string|int|float> e.g., ['model' => 'claude-3-opus', 'cost_center' => 'marketing']
     */
    public function tags(): array;
}
