<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Models\WorkflowRun;

trait ManagesState
{
    protected function applyMutation(WorkflowRun $run, array $mutation, StateReducerInterface $reducer): array
    {
        $newState = $reducer->reduce($run->state, $mutation);
        $run->state = $newState;

        return $newState;
    }

}
