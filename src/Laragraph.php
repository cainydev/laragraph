<?php

namespace Cainy\Laragraph;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Engine\Concerns\ManagesState;
use Cainy\Laragraph\Engine\Concerns\TracksPointers;
use Cainy\Laragraph\Engine\ExecuteNode;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Events\WorkflowResumed;
use Cainy\Laragraph\Events\WorkflowStarted;
use Cainy\Laragraph\Exceptions\InvalidStatusTransition;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Routing\Send;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

readonly class Laragraph
{
    use ManagesState, TracksPointers;

    /**
     * Start a new workflow run.
     *
     * @param  class-string<Workflow>  $workflowClass
     *
     * @throws Throwable
     */
    public function run(string $workflowClass, array $initialState = [], array $metadata = []): WorkflowRun
    {
        $workflow = app($workflowClass);
        $compiled = $workflow->compile();
        $startTargets = $compiled->getStartNodes($initialState);

        $run = DB::transaction(function () use ($workflowClass, $initialState, $startTargets, $metadata): WorkflowRun {
            $run = WorkflowRun::create([
                'key' => $workflowClass,
                'state' => $initialState,
                'metadata' => $metadata ?: null,
                'status' => RunStatus::Running,
            ]);

            $this->pushTargetPointers($run, $startTargets);
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowStarted($run->id, $workflowClass));

        $workflow->onStarting($run);

        $this->dispatchTargets($run->id, $startTargets, $compiled);

        return $run;
    }

    /**
     * Start a child workflow run, linking it to a parent.
     *
     * @throws Throwable
     */
    public function startChildWorkflow(Workflow $workflow, array $initialState, int $parentRunId, string $parentNodeName): WorkflowRun
    {
        $workflowClass = get_class($workflow);
        $compiled = $workflow->compile();
        $startTargets = $compiled->getStartNodes($initialState);

        $run = DB::transaction(function () use ($workflowClass, $initialState, $startTargets, $parentRunId, $parentNodeName): WorkflowRun {
            $run = WorkflowRun::create([
                'parent_run_id' => $parentRunId,
                'parent_node_name' => $parentNodeName,
                'key' => $workflowClass,
                'state' => $initialState,
                'status' => RunStatus::Running,
            ]);

            $this->pushTargetPointers($run, $startTargets);
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowStarted($run->id, $workflowClass));

        $this->dispatchTargets($run->id, $startTargets, $compiled);

        return $run;
    }

    /**
     * Resume a parent run from a completed child workflow.
     * Sets the parent back to Running and re-dispatches the waiting node.
     *
     * @throws Throwable
     */
    public function resumeFromChild(int $parentRunId, string $parentNodeName): void
    {
        $workflowKey = '';

        $resumed = DB::transaction(function () use ($parentRunId, &$workflowKey): bool {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($parentRunId);

            if ($run->status !== RunStatus::Paused) {
                return false;
            }

            $workflowKey = $run->key ?? '';
            $run->status = RunStatus::Running;
            $run->save();

            return true;
        });

        if (! $resumed) {
            return;
        }

        Event::dispatch(new WorkflowResumed($parentRunId, $workflowKey));

        $compiled = $workflowKey !== '' ? $this->hydrateWorkflowByKey($workflowKey) : null;
        ExecuteNode::dispatchNode($parentRunId, $parentNodeName, null, $compiled);
    }

    /**
     * Pause an active workflow run. Only runs with status "running" can be paused.
     *
     * @throws ModelNotFoundException If the run ID does not exist.
     * @throws InvalidStatusTransition If the run is not currently 'running'.
     * @throws Throwable For underlying database or transaction failures.
     */
    public function pause(int $runId): WorkflowRun
    {
        return DB::transaction(function () use ($runId): WorkflowRun {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);

            if ($run->status !== RunStatus::Running) {
                throw InvalidStatusTransition::notRunning($run);
            }

            $run->status = RunStatus::Paused;
            $run->save();

            return $run;
        });
    }

    /**
     * Abort a workflow run. Aborting sets the run status to "failed" and
     * clears all active pointers, effectively halting execution.
     *
     * @throws Throwable
     */
    public function abort(int $runId): WorkflowRun
    {
        $run = DB::transaction(function () use ($runId): WorkflowRun {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);

            $run->status = RunStatus::Failed;
            $run->active_pointers = [];
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowFailed($runId, new \RuntimeException('Workflow aborted.'), $run->key ?? ''));

        return $run;
    }

    /**
     * Resume a workflow run. Only runs with status "paused" can be resumed. Optionally,
     * additional state can be merged into the run's existing state upon resumption.
     *
     * @throws ModelNotFoundException If the run ID does not exist.
     * @throws InvalidStatusTransition If the run is not currently 'paused'.
     * @throws Throwable For underlying database or transaction failures.
     */
    public function resume(int $runId, array $additionalState = []): WorkflowRun
    {
        $pointers = [];
        $workflowKey = '';

        $run = DB::transaction(function () use ($runId, $additionalState, &$pointers, &$workflowKey): WorkflowRun {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);

            if ($run->status !== RunStatus::Paused) {
                throw InvalidStatusTransition::notPaused($run);
            }

            if (! empty($additionalState)) {
                $compiled = $this->hydrateWorkflow($run);
                $reducer = $compiled->getReducer();
                $this->applyMutation($run, $additionalState, $reducer);
            }

            $workflowKey = $run->key ?? '';
            $run->status = RunStatus::Running;
            $run->save();

            $pointers = $run->active_pointers ?? [];

            return $run;
        });

        Event::dispatch(new WorkflowResumed($runId, $workflowKey));

        $compiled = $workflowKey !== '' ? $this->hydrateWorkflowByKey($workflowKey) : null;
        foreach ($pointers as $nodeName) {
            ExecuteNode::dispatchNode($runId, $nodeName, null, $compiled);
        }

        return $run;
    }

    private function hydrateWorkflow(WorkflowRun $run): CompiledWorkflow
    {
        if ($run->key === null) {
            throw new \RuntimeException("WorkflowRun [{$run->id}] has no workflow class key.");
        }

        return $this->hydrateWorkflowByKey($run->key);
    }

    private function hydrateWorkflowByKey(string $key): CompiledWorkflow
    {
        return app($key)->compile();
    }

    /**
     * Push pointer entries for a mix of string node names and Send objects.
     *
     * @param  array<string|Send>  $targets
     */
    private function pushTargetPointers(WorkflowRun $run, array $targets): void
    {
        foreach ($targets as $target) {
            if ($target instanceof Send) {
                $this->pushPointers($run, $target->nodeName);
            } elseif ($target !== Workflow::END) {
                $this->pushPointers($run, $target);
            }
        }
    }

    /**
     * Dispatch ExecuteNode for a mix of string node names and Send objects.
     *
     * @param  array<string|Send>  $targets
     */
    private function dispatchTargets(int $runId, array $targets, ?CompiledWorkflow $workflow = null): void
    {
        foreach ($targets as $target) {
            if ($target instanceof Send) {
                ExecuteNode::dispatchNode($runId, $target->nodeName, $target->payload, $workflow);
            } elseif ($target !== Workflow::END) {
                ExecuteNode::dispatchNode($runId, $target, null, $workflow);
            }
        }
    }
}
