<?php

namespace Cainy\Laragraph\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cainy\Laragraph\Laragraph
 *
 * @method static \Cainy\Laragraph\Models\WorkflowRun run(string $workflowClass, array $initialState = [])
 * @method static void resumeFromChild(int $parentRunId, string $parentNodeName)
 * @method static \Cainy\Laragraph\Models\WorkflowRun resume(int $runId, array $additionalState = [])
 * @method static \Cainy\Laragraph\Models\WorkflowRun pause(int $runId)
 * @method static \Cainy\Laragraph\Models\WorkflowRun abort(int $runId)
 */
class Laragraph extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Cainy\Laragraph\Laragraph::class;
    }
}
