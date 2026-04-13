<?php

namespace Cainy\Laragraph\Tests;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Engine\NodeExecutionContext;

/**
 * Bind a Workflow instance to the container under a stable test key and return
 * the key so it can be passed to Laragraph::run().
 *
 * @return class-string<Workflow>
 */
function bindTestWorkflow(string $key, Workflow $workflow): string
{
    app()->bind($key, fn () => $workflow);

    return $key; // @phpstan-ignore-line
}

/**
 * Build a minimal NodeExecutionContext for unit-testing nodes.
 */
function makeContext(int $runId = 1, string $nodeName = 'test', int $attempt = 1, ?array $isolatedPayload = null, int $pendingCount = 1): NodeExecutionContext
{
    return new NodeExecutionContext(
        runId: $runId,
        workflowKey: 'test-workflow',
        nodeName: $nodeName,
        attempt: $attempt,
        maxAttempts: 3,
        createdAt: new \DateTimeImmutable,
        isolatedPayload: $isolatedPayload,
        pendingCount: $pendingCount,
    );
}
