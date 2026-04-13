<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Models\WorkflowRun;

trait ManagesState
{
    protected function applyMutation(WorkflowRun $run, array $mutation, StateReducerInterface $reducer): array
    {
        // Keys set to null in a mutation are tombstones — remove them from state.
        $nullKeys = array_keys(array_filter($mutation, fn ($v) => $v === null));

        $newState = $reducer->reduce($run->state, $mutation);

        foreach ($nullKeys as $key) {
            unset($newState[$key]);
        }

        $run->state = $newState;

        return $newState;
    }
}
