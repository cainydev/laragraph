<?php

namespace Cainy\Laragraph\Jobs;

use Cainy\Laragraph\Laragraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resumes a paused WorkflowRun after its DelayNode timer has elapsed.
 * Dispatched by DelayNode with a queue delay equal to the requested seconds.
 */
class ResumeWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function handle(Laragraph $laragraph): void
    {
        $laragraph->resume($this->runId);
    }
}
