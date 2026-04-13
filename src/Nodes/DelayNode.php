<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Jobs\ResumeWorkflowJob;

/**
 * Delay node — pauses execution for a given number of seconds.
 *
 * On first execution it stores a resume-after timestamp, dispatches a
 * ResumeWorkflowJob with the matching queue delay, and pauses the run.
 * No CRON polling is required — the job wakes the workflow automatically.
 *
 * On resume it checks if enough time has passed and completes normally.
 */
final class DelayNode implements Node
{
    public function __construct(
        public readonly int $seconds = 60,
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $resumeKey = "__delay_resume_{$context->nodeName}";
        $resumeAt = $state[$resumeKey] ?? null;

        if ($resumeAt === null) {
            // First execution — store resume timestamp, enqueue auto-resume job, and pause.
            $resumeAt = now()->addSeconds($this->seconds)->timestamp;
            ResumeWorkflowJob::dispatch($context->runId)->delay($this->seconds);
            throw new NodePausedException(
                nodeName: $context->nodeName,
                stateMutation: [$resumeKey => $resumeAt],
            );
        }

        if (now()->timestamp < $resumeAt) {
            // Guard against early resumption (e.g. manual resume() call).
            // Pause again; the already-enqueued job will fire at the correct time.
            throw new NodePausedException(nodeName: $context->nodeName);
        }

        // Delay complete — clean up the marker key
        return [$resumeKey => null];
    }
}
