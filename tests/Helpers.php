<?php

namespace Cainy\Laragraph\Tests;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Engine\WorkflowRegistry;

/**
 * Register a workflow builder for testing and return the key.
 */
function registerTestWorkflow(string $name, Workflow $workflow): string
{
    app(WorkflowRegistry::class)->register($name, fn () => $workflow);

    return $name;
}

/**
 * Build a minimal NodeExecutionContext for unit-testing nodes.
 */
function makeContext(int $runId = 1, string $nodeName = 'test', int $attempt = 1, ?array $isolatedPayload = null): NodeExecutionContext
{
    return new NodeExecutionContext(
        runId: $runId,
        workflowKey: 'test-workflow',
        nodeName: $nodeName,
        attempt: $attempt,
        maxAttempts: 3,
        createdAt: new \DateTimeImmutable,
        isolatedPayload: $isolatedPayload,
    );
}
