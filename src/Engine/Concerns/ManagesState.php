<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Models\WorkflowRun;

trait ManagesState
{
    protected function applyMutation(WorkflowRun $run, array $mutation, StateReducerInterface $reducer): array
    {
        $newState = $reducer->reduce($run->state, $mutation);
        $run->state = $newState;

        return $newState;
    }

    protected function updateStatus(WorkflowRun $run, RunStatus $status, ?string $currentNode = null): void
    {
        $run->status = $status;

        if ($currentNode !== null) {
            $run->current = $currentNode;
        }
    }
}
