<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Events\HumanInterventionRequired;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Illuminate\Support\Facades\Event;

/**
 * A convenience node that always pauses the workflow.
 *
 * Prefer using the LangGraph-style interrupt API instead:
 *   $workflow->interruptBefore('node-name')  // pause before a node
 *   $workflow->interruptAfter('node-name')   // pause after a node
 *
 * The engine handles pause/resume automatically — on resume, it detects
 * the __interrupt marker and skips re-pausing. This node exists for cases
 * where a node itself decides dynamically to pause (throws NodePausedException).
 */
class HumanInterruptNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        Event::dispatch(new HumanInterventionRequired($context->runId));

        throw new NodePausedException($context->nodeName);
    }
}
