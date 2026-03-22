<?php

namespace Cainy\Laragraph;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Engine\Concerns\TracksPointers;
use Cainy\Laragraph\Engine\ExecuteNode;
use Cainy\Laragraph\Engine\WorkflowRegistry;
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
    use TracksPointers;

    public function __construct(
        private WorkflowRegistry $registry,
    ) {}

    /**
     * Start a new workflow run by name.
     *
     * @throws Throwable
     */
    public function start(string $workflowName, array $initialState = [], ?string $key = null): WorkflowRun
    {
        $workflow = $this->registry->resolve($workflowName);
        $startTargets = $workflow->getStartNodes($initialState);

        $run = DB::transaction(function () use ($workflowName, $initialState, $key, $startTargets): WorkflowRun {
            $run = WorkflowRun::create([
                'key' => $key ?? $workflowName,
                'state' => $initialState,
                'status' => RunStatus::Running,
            ]);

            $this->pushTargetPointers($run, $startTargets);
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowStarted($run->id, $workflowName));

        $this->dispatchTargets($run->id, $startTargets);

        return $run;
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
    private function dispatchTargets(int $runId, array $targets): void
    {
        foreach ($targets as $target) {
            if ($target instanceof Send) {
                ExecuteNode::dispatch($runId, $target->nodeName, $target->payload);
            } elseif ($target !== Workflow::END) {
                ExecuteNode::dispatch($runId, $target);
            }
        }
    }

    /**
     * Start a new workflow run by blueprint.
     *
     * @throws Throwable
     */
    public function startFromBlueprint(Workflow $blueprint, array $initialState = [], ?string $key = null): WorkflowRun
    {
        $snapshot = $blueprint->toJson();
        $compiled = Workflow::fromJson($snapshot);
        $startTargets = $compiled->getStartNodes($initialState);

        $run = DB::transaction(function () use ($key, $snapshot, $initialState, $startTargets): WorkflowRun {
            $run = WorkflowRun::create([
                'key' => $key,
                'snapshot' => json_decode($snapshot, true),
                'state' => $initialState,
                'status' => RunStatus::Running,
            ]);

            $this->pushTargetPointers($run, $startTargets);
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowStarted($run->id, $key ?? 'blueprint'));

        $this->dispatchTargets($run->id, $startTargets);

        return $run;
    }

    /**
     * Start a child workflow run from a compiled workflow instance, linking it to a parent.
     *
     * @throws Throwable
     */
    public function startChildWorkflow(CompiledWorkflow $compiled, array $initialState, int $parentRunId, string $parentNodeName, ?string $key = null): WorkflowRun
    {
        $startTargets = $compiled->getStartNodes($initialState);

        $run = DB::transaction(function () use ($compiled, $initialState, $startTargets, $parentRunId, $parentNodeName, $key): WorkflowRun {
            $run = WorkflowRun::create([
                'parent_run_id' => $parentRunId,
                'parent_node_name' => $parentNodeName,
                'key' => $key,
                'snapshot' => $compiled->toArray(),
                'state' => $initialState,
                'status' => RunStatus::Running,
            ]);

            $this->pushTargetPointers($run, $startTargets);
            $run->save();

            return $run;
        });

        Event::dispatch(new WorkflowStarted($run->id, $key ?? 'child'));

        $this->dispatchTargets($run->id, $startTargets);

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
        DB::transaction(function () use ($parentRunId): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($parentRunId);

            if ($run->status !== RunStatus::Paused) {
                return;
            }

            $run->status = RunStatus::Running;
            $run->save();
        });

        ExecuteNode::dispatch($parentRunId, $parentNodeName);
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

        Event::dispatch(new WorkflowFailed($runId, new \RuntimeException('Workflow aborted.')));

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

        $run = DB::transaction(function () use ($runId, $additionalState, &$pointers): WorkflowRun {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($runId);

            if ($run->status !== RunStatus::Paused) {
                throw InvalidStatusTransition::notPaused($run);
            }

            if (! empty($additionalState)) {
                $run->state = array_merge($run->state, $additionalState);
            }

            $run->status = RunStatus::Running;
            $run->save();

            $pointers = $run->active_pointers ?? [];

            return $run;
        });

        Event::dispatch(new WorkflowResumed($runId));

        foreach ($pointers as $nodeName) {
            ExecuteNode::dispatch($runId, $nodeName);
        }

        return $run;
    }
}
