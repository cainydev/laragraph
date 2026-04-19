<?php

namespace Cainy\Laragraph\Listeners;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;

/**
 * When a child WorkflowRun fails, propagate the failure to its parent by
 * marking the parent's waiting sub-graph node as failed. The parent workflow
 * may opt out on a per-node basis by overriding Workflow::shouldCascadeFailure().
 */
class CascadeChildFailure
{
    public function handle(WorkflowFailed $event): void
    {
        $child = WorkflowRun::find($event->runId);

        if ($child === null || $child->parent_run_id === null || $child->parent_node_name === null) {
            return;
        }

        $parent = WorkflowRun::find($child->parent_run_id);
        if ($parent === null || $parent->key === null) {
            return;
        }

        // Resolve the parent-side sub-graph node and ask whether it wants cascade.
        try {
            $parentWorkflow = app($parent->key);
            if (! $parentWorkflow instanceof Workflow) {
                return;
            }
            $subNode = $parentWorkflow->compile()->resolveNode($child->parent_node_name);
        } catch (\Throwable) {
            return;
        }

        if (! $subNode instanceof Workflow) {
            return;
        }

        if (! $subNode->shouldCascadeFailure()) {
            return;
        }

        app(Laragraph::class)->failFromChild(
            parentRunId: $parent->id,
            parentNodeName: $child->parent_node_name,
            childException: $event->exception,
        );
    }
}
