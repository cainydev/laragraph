<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Contracts\StateReducerInterface;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

class StateManager
{
    public function __construct(private readonly StateReducerInterface $reducer) {}

    public function applyMutation(int $runId, array $mutation): array
    {
        return DB::transaction(function () use ($runId, $mutation): array {
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);
            $newState = $this->reducer->reduce($run->state, $mutation);
            $run->state = $newState;
            $run->save();

            return $newState;
        });
    }

    public function getState(int $runId): array
    {
        return WorkflowRun::findOrFail($runId)->state;
    }

    public function updateRunStatus(int $runId, RunStatus $status, ?string $currentNode = null): void
    {
        DB::transaction(function () use ($runId, $status, $currentNode): void {
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);
            $run->status = $status;
            if ($currentNode !== null) {
                $run->current = $currentNode;
            }
            $run->save();
        });
    }
}
