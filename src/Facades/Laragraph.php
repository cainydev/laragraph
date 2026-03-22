<?php

namespace Cainy\Laragraph\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cainy\Laragraph\Laragraph
 *
 * @method static void register(string $name, string|callable $definition)
 * @method static \Cainy\Laragraph\Models\WorkflowRun start(string $workflowName, array $initialState = [], ?string $key = null)
 * @method static \Cainy\Laragraph\Models\WorkflowRun startFromBlueprint(\Cainy\Laragraph\Builder\Workflow $blueprint, array $initialState = [], ?string $key = null)
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
