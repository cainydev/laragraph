<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasMiddleware;
use Cainy\Laragraph\Contracts\HasQueue;
use Cainy\Laragraph\Contracts\HasRetryPolicy;
use Cainy\Laragraph\Contracts\HasTags;
use Cainy\Laragraph\Contracts\IsFanInBarrier;
use Cainy\Laragraph\Engine\Concerns\ManagesRouting;
use Cainy\Laragraph\Engine\Concerns\ManagesState;
use Cainy\Laragraph\Engine\Concerns\TracksPointers;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\HumanInterventionRequired;
use Cainy\Laragraph\Events\NodeCompleted;
use Cainy\Laragraph\Events\NodeExecuting;
use Cainy\Laragraph\Events\NodeFailed;
use Cainy\Laragraph\Events\WorkflowCompleted;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Exceptions\NodeExecutionException;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Exceptions\NodeSkippedException;
use Cainy\Laragraph\Exceptions\RecursionLimitExceeded;
use Cainy\Laragraph\Laragraph;
use Cainy\Laragraph\Models\NodeExecution;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Routing\Send;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

class ExecuteNode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ManagesRouting, ManagesState, TracksPointers;

    public int $tries;

    public int $timeout;

    /**
     * Mark the job as failed (rather than silently re-queuing) when it times out.
     * Without this, a timed-out LLM call would consume an attempt and retry quietly.
     */
    public bool $failOnTimeout = true;

    public array $backoffIntervals = [];

    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
        public readonly ?array $isolatedPayload = null,
    ) {
        $this->tries = config('laragraph.max_node_attempts', 3);
        $this->timeout = config('laragraph.node_timeout', 60);
        $this->onQueue(config('laragraph.queue', 'default'));
        $connection = config('laragraph.connection');
        if ($connection !== null) {
            $this->onConnection($connection);
        }
    }

    public function displayName(): string
    {
        return "ExecuteNode [{$this->nodeName}] on run [{$this->runId}]";
    }

    /**
     * Delegate job middleware to the node if it implements HasMiddleware.
     * Nodes can return e.g. [new RateLimited('anthropic')] to throttle LLM calls.
     *
     * @return array<object>
     */
    public function middleware(): array
    {
        try {
            $run = WorkflowRun::find($this->runId);
            if ($run === null) {
                return [];
            }
            $node = $this->hydrateWorkflow($run)->resolveNode($this->nodeName);
            if ($node instanceof HasMiddleware) {
                return $node->middleware();
            }
        } catch (Throwable) {
            // Node unresolvable — no middleware.
        }

        return [];
    }

    /**
     * Dispatch an ExecuteNode job, applying per-node queue/connection from HasQueue if available.
     */
    public static function dispatchNode(int $runId, string $nodeName, ?array $isolatedPayload = null, ?CompiledWorkflow $workflow = null): void
    {
        $job = new self($runId, $nodeName, $isolatedPayload);

        if ($workflow !== null) {
            try {
                $node = $workflow->resolveNode($nodeName);
                if ($node instanceof HasQueue) {
                    $job->onQueue($node->queue());
                    if ($node->connection() !== null) {
                        $job->onConnection($node->connection());
                    }
                }
            } catch (Throwable) {
                // Node not resolvable (e.g. string class not bound yet) — use defaults.
            }
        }

        if (config('laragraph.after_commit', false)) {
            $job->afterCommit();
        }

        dispatch($job);
    }

    public function backoff(): array
    {
        return $this->backoffIntervals;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::findOrFail($this->runId);

        $workflowKey = $run->key ?? '';

        if ($run->status === RunStatus::Failed || $run->status === RunStatus::Paused) {
            return;
        }

        $workflow = $this->hydrateWorkflow($run);
        $reducer = $workflow->getReducer();

        $interruptMarker = $this->interruptMarker($run);
        $resumingAfter = $interruptMarker === $this->nodeName
            && $workflow->shouldInterruptAfter($this->nodeName);

        // interrupt_before: needs a short locked write, no heavy I/O.
        if (! $resumingAfter) {
            $node = $workflow->resolveNode($this->nodeName);

            if ($node instanceof HasRetryPolicy) {
                $policy = $node->retryPolicy();
                $this->tries = $policy->maxAttempts;
                $this->backoffIntervals = $policy->calculateBackoff();
            }

            $resumingBefore = $interruptMarker === $this->nodeName
                && $workflow->shouldInterruptBefore($this->nodeName);

            if (! $resumingBefore && $workflow->shouldInterruptBefore($this->nodeName)) {
                DB::transaction(function () use ($run): void {
                    /** @var WorkflowRun $run */
                    $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);
                    if ($run->status === RunStatus::Failed || $run->status === RunStatus::Paused) {
                        return;
                    }
                    $this->setInterruptMarker($run, $this->nodeName);
                    $run->status = RunStatus::Paused;
                    $run->save();
                });

                return;
            }

            Event::dispatch(new NodeExecuting($this->runId, $this->nodeName));

            $contextRun = $run;
            $isPreCheckSkip = false;

            if ($node instanceof IsFanInBarrier) {
                $isPreCheckSkip = DB::transaction(function () use (&$contextRun, $workflow): bool {
                    /** @var WorkflowRun $contextRun */
                    $contextRun = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

                    if ($contextRun->status === RunStatus::Failed || $contextRun->status === RunStatus::Paused) {
                        return true;
                    }

                    // Phase 1: Early arrivals — predecessors are not yet fully complete.
                    // Remove this pointer to maintain the 1:1 pointer/job equilibrium,
                    // then skip so the next arrival can re-check once more work lands.
                    $predecessors = $workflow->getIncomingNodesFor($this->nodeName);

                    foreach ($predecessors as $predecessor) {
                        $expected = $this->expectedSpawns($contextRun, $predecessor);
                        $completed = $this->completedSpawns($contextRun, $predecessor);

                        if ($expected === 0 || $completed < $expected) {
                            $this->removePointer($contextRun, $this->nodeName);
                            $contextRun->save();

                            return true;
                        }
                    }

                    // Phase 2: All predecessors are done. If multiple pointer entries exist
                    // for this barrier, this is a competing late arrival — absorb one pointer
                    // and skip so only one job proceeds to handle().
                    $pointerCount = count(array_filter(
                        $contextRun->active_pointers ?? [],
                        fn (string $p) => $p === $this->nodeName,
                    ));

                    if ($pointerCount > 1) {
                        $this->removePointer($contextRun, $this->nodeName);
                        $contextRun->save();

                        return true;
                    }

                    // Phase 3: Exactly one pointer remains — this job is the sole claimant.
                    // Leave the pointer for the main transaction to consume via finalizePointers.
                    return false;
                });
            }

            if ($isPreCheckSkip) {
                return;
            }

            $context = NodeExecutionContext::fromJob($contextRun, $this->nodeName, $this->attempts(), $this->tries, $this->isolatedPayload);

            try {
                $mutation = $node->handle($context, $contextRun->state);
            } catch (NodeSkippedException) {
                $this->commitSkip($workflowKey);

                return;
            } catch (NodePausedException $e) {
                $this->commitPause($e, $workflowKey);

                return;
            } catch (Throwable $e) {
                throw new NodeExecutionException($this->nodeName, $this->runId, previous: $e);
            }
        } else {
            $mutation = [];
            $node = $workflow->resolveNode($this->nodeName);
        }

        /** @var array<string|Send> $nextTargets */
        $nextTargets = [];
        $completed = false;
        $parentRunId = null;
        $parentNodeName = null;

        DB::transaction(function () use (
            $mutation, $node, $workflow, $reducer, $resumingAfter,
            &$nextTargets, &$completed, &$parentRunId, &$parentNodeName, &$workflowKey,
        ): void {
            /** @var WorkflowRun $freshRun */
            $freshRun = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            $workflowKey = $freshRun->key ?? '';

            if ($freshRun->status === RunStatus::Failed || $freshRun->status === RunStatus::Paused) {
                return;
            }

            if (! in_array($this->nodeName, $freshRun->active_pointers ?? [], true)) {
                return;
            }

            // Recursion counter — increment atomically inside the lock.
            $nodeExecutions = ($freshRun->node_executions ?? 0) + 1;
            if ($nodeExecutions > $workflow->getRecursionLimit()) {
                $freshRun->status = RunStatus::Failed;
                $freshRun->save();
                throw new RecursionLimitExceeded($this->runId, $workflow->getRecursionLimit());
            }
            $freshRun->node_executions = $nodeExecutions;

            if ($resumingAfter) {
                $newState = $freshRun->state;
                $this->setInterruptMarker($freshRun, null);
            } else {
                // Detect direct-Send returns (e.g. SendNode).
                $directSends = ! empty($mutation) && array_is_list($mutation)
                    && count(array_filter($mutation, fn ($v) => $v instanceof Send)) === count($mutation)
                    ? $mutation
                    : [];

                if (empty($directSends)) {
                    $newState = $this->applyMutation($freshRun, $mutation, $reducer);
                } else {
                    $newState = $freshRun->state;
                }
                $this->setInterruptMarker($freshRun, null);
                $freshRun->state = $newState;

                $tags = $node instanceof HasTags ? $node->tags() : [];
                $capturedRunId = $this->runId;
                $capturedNodeName = $this->nodeName;
                $capturedMutation = $mutation;
                $capturedTags = $tags;
                DB::afterCommit(function () use ($capturedRunId, $capturedNodeName, $capturedMutation, $capturedTags): void {
                    Event::dispatch(new NodeCompleted($capturedRunId, $capturedNodeName, $capturedMutation, $capturedTags));
                });

                if ($node instanceof HasTags && ! empty($tags)) {
                    NodeExecution::create([
                        'run_id' => $this->runId,
                        'node_name' => $this->nodeName,
                        'attempt' => $this->attempts(),
                        'tags' => $tags,
                        'executed_at' => now(),
                    ]);
                }

                // interrupt_after: pause after node ran, before edges evaluate.
                if ($workflow->shouldInterruptAfter($this->nodeName)) {
                    $freshRun->state = $newState;
                    $this->setInterruptMarker($freshRun, $this->nodeName);
                    $freshRun->current = $this->nodeName;
                    $freshRun->status = RunStatus::Paused;
                    $freshRun->save();

                    return;
                }

                if (! empty($directSends)) {
                    $nextTargets = $directSends;

                    // Record expected spawn counts per target so downstream barriers
                    // know exactly how many workers to wait for.
                    foreach ($directSends as $send) {
                        $this->incrementExpectedSpawns($freshRun, $send->nodeName);
                    }

                    $this->finalizePointers($freshRun, $nextTargets, $completed, $parentRunId, $parentNodeName);

                    return;
                }
            }

            // Record that this node has committed its result so downstream barriers
            // can count completions against the expected spawn totals.
            $this->incrementCompletedSpawns($freshRun, $this->nodeName);

            $nextTargets = $workflow->resolveNextNodes($this->nodeName, $newState);

            // Record expected spawn counts for every dispatched target so downstream
            // barriers know exactly how many completions to wait for. Send objects
            // each dispatch one worker job; normal string targets dispatch one job each.
            foreach ($nextTargets as $target) {
                if ($target instanceof Send) {
                    $this->incrementExpectedSpawns($freshRun, $target->nodeName);
                } elseif ($target !== Workflow::END) {
                    $this->incrementExpectedSpawns($freshRun, $target);
                }
            }

            $this->finalizePointers($freshRun, $nextTargets, $completed, $parentRunId, $parentNodeName);
        });

        if ($completed) {
            Event::dispatch(new WorkflowCompleted($this->runId, $workflowKey));
            $this->fireCompletedHook($workflowKey);

            if ($parentRunId !== null && $parentNodeName !== null) {
                app(Laragraph::class)->resumeFromChild($parentRunId, $parentNodeName, $this->runId);
            }

            return;
        }

        $freshStatus = WorkflowRun::find($this->runId)?->status;
        if ($freshStatus !== RunStatus::Running) {
            return;
        }

        foreach ($nextTargets as $target) {
            if ($target instanceof Send) {
                static::dispatchNode($this->runId, $target->nodeName, $target->payload, $workflow);
            } elseif ($target !== Workflow::END) {
                static::dispatchNode($this->runId, $target, null, $workflow);
            }
        }
    }

    /**
     * Commit a NodeSkippedException (node signalled it should be skipped).
     */
    private function commitSkip(string &$workflowKey): void
    {
        $completed = false;
        $parentRunId = null;
        $parentNodeName = null;

        DB::transaction(function () use (&$workflowKey, &$completed, &$parentRunId, &$parentNodeName): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);
            $workflowKey = $run->key ?? '';

            $this->removePointer($run, $this->nodeName);

            if (! $this->hasActivePointers($run)) {
                $run->status = RunStatus::Completed;
                $completed = true;
                $parentRunId = $run->parent_run_id;
                $parentNodeName = $run->parent_node_name;
            }

            $run->save();
        });

        if ($completed) {
            Event::dispatch(new WorkflowCompleted($this->runId, $workflowKey));
            $this->fireCompletedHook($workflowKey);

            if ($parentRunId !== null && $parentNodeName !== null) {
                app(Laragraph::class)->resumeFromChild($parentRunId, $parentNodeName, $this->runId);
            }
        }
    }

    /**
     * Commit a NodePausedException (GateNode / sub-workflow pause).
     */
    private function commitPause(NodePausedException $e, string &$workflowKey): void
    {
        DB::transaction(function () use ($e, &$workflowKey): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);
            $workflowKey = $run->key ?? '';

            // A cascaded child failure may have already marked this run Failed
            // (sync queue: child runs and fails before we unwind to commit the
            // pause). Don't overwrite the terminal Failed state with Paused.
            if ($run->status === RunStatus::Failed || $run->status === RunStatus::Completed) {
                return;
            }

            if (! empty($e->stateMutation)) {
                $reducer = $this->hydrateWorkflow($run)->getReducer();
                $this->applyMutation($run, $e->stateMutation, $reducer);
            }

            $this->setInterruptMarker($run, $this->nodeName);

            if ($e->gateReason !== null) {
                $this->setGateReason($run, $e->gateReason);
            }

            if ($e->childRunId !== null && $e->childRunIdIsPersistable) {
                $this->setChildRunId($run, $this->nodeName, $e->childRunId);
            }

            $run->status = RunStatus::Paused;
            $run->save();
        });

        Event::dispatch(new HumanInterventionRequired(
            $this->runId,
            $this->nodeName,
            $e->gateReason,
        ));

        // If a child workflow already completed (sync queue), resume immediately.
        if ($e->childRunId !== null) {
            $childRun = WorkflowRun::find($e->childRunId);
            if ($childRun?->status === RunStatus::Completed) {
                app(Laragraph::class)->resumeFromChild($this->runId, $this->nodeName, $e->childRunId);
            }
        }
    }

    /**
     * Update active_pointers and run status from resolved next targets.
     *
     * @param  array<string|Send>  $nextTargets
     */
    private function finalizePointers(WorkflowRun $run, array $nextTargets, bool &$completed, ?int &$parentRunId, ?string &$parentNodeName): void
    {
        $nextNodeNames = array_values(array_filter(
            $nextTargets,
            fn ($t) => ! ($t instanceof Send) && $t !== Workflow::END,
        ));

        $this->removePointer($run, $this->nodeName);
        if (! empty($nextNodeNames)) {
            $this->pushPointers($run, ...$nextNodeNames);
        }

        $sendTargets = array_values(array_filter($nextTargets, fn ($t) => $t instanceof Send));
        foreach ($sendTargets as $send) {
            $this->pushPointers($run, $send->nodeName);
        }

        $run->current = $this->nodeName;

        if (! $this->hasActivePointers($run)) {
            $run->status = RunStatus::Completed;
            $completed = true;
            $parentRunId = $run->parent_run_id;
            $parentNodeName = $run->parent_node_name;
        } else {
            $run->status = RunStatus::Running;
        }

        $run->save();
    }

    public function failed(Throwable $exception): void
    {
        $root = $exception->getPrevious() ?? $exception;

        // RecursionLimitExceeded is never retried
        if ($root instanceof RecursionLimitExceeded) {
            $workflowKey = '';
            try {
                $workflowKey = $this->markFailed($root);
            } catch (Throwable) {
                // best-effort; DB may be unavailable
            } finally {
                Event::dispatch(new NodeFailed($this->runId, $this->nodeName, $root));
                Event::dispatch(new WorkflowFailed($this->runId, $root, $workflowKey));
                $this->fireFailedHook($workflowKey, $root);
            }

            return;
        }

        // Check if the node's retry policy allows retrying this exception.
        // Note: on async queue workers, failed() is only called after all retries
        // are exhausted (attempts() === tries). The attempts() < tries check here
        // handles the sync queue case where failed() fires on the first attempt.
        if ($this->attempts() < $this->tries) {
            try {
                $run = WorkflowRun::find($this->runId);
                if ($run !== null) {
                    $workflow = $this->hydrateWorkflow($run);
                    $node = $workflow->resolveNode($this->nodeName);

                    if ($node instanceof HasRetryPolicy && ! $node->retryPolicy()->shouldRetry($root)) {
                        $workflowKey = '';
                        try {
                            $workflowKey = $this->markFailed($root);
                        } catch (Throwable) {
                            // best-effort; DB may be unavailable
                        } finally {
                            Event::dispatch(new NodeFailed($this->runId, $this->nodeName, $root));
                            Event::dispatch(new WorkflowFailed($this->runId, $root, $workflowKey));
                            $this->fireFailedHook($workflowKey, $root);
                        }

                        return;
                    }
                }
            } catch (Throwable) {
                // If we can't check the retry policy, fall through to default behaviour
            }
        }

        $workflowKey = '';
        try {
            $workflowKey = $this->markFailed($root);
        } catch (Throwable) {
            // best-effort; DB may be unavailable
        } finally {
            Event::dispatch(new NodeFailed($this->runId, $this->nodeName, $root));
            Event::dispatch(new WorkflowFailed($this->runId, $root, $workflowKey));
            $this->fireFailedHook($workflowKey, $root);
        }
    }

    private function markFailed(Throwable $root): string
    {
        $workflowKey = '';

        DB::transaction(function () use ($root, &$workflowKey): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            $workflowKey = $run->key ?? '';

            // If the run already completed/failed via a successful retry, don't overwrite it.
            if ($run->status === RunStatus::Completed || $run->status === RunStatus::Failed) {
                return;
            }

            $this->setErrorSummary($run, [
                'node' => $this->nodeName,
                'class' => get_class($root),
                'message' => $root->getMessage(),
                'file' => $root->getFile(),
                'line' => $root->getLine(),
            ]);

            $this->removePointer($run, $this->nodeName);

            $run->status = RunStatus::Failed;
            $run->save();

            $this->recordFailedExecution($root);
        });

        return $workflowKey;
    }

    /**
     * Persist a failed execution row on workflow_node_executions. Runs inside
     * the markFailed() transaction so state and the failure row commit atomically.
     */
    private function recordFailedExecution(Throwable $root): void
    {
        NodeExecution::create([
            'run_id' => $this->runId,
            'node_name' => $this->nodeName,
            'attempt' => $this->attempts(),
            'tags' => null,
            'executed_at' => now(),
            'error_class' => get_class($root),
            'error_message' => $root->getMessage(),
            'error_trace' => $root->getTraceAsString(),
            'failed_at' => now(),
        ]);
    }

    private function fireCompletedHook(string $workflowKey): void
    {
        try {
            $run = WorkflowRun::find($this->runId);
            if ($run === null || $workflowKey === '') {
                return;
            }
            app($workflowKey)->onCompleted($run);
        } catch (Throwable) {
        }
    }

    private function fireFailedHook(string $workflowKey, Throwable $exception): void
    {
        try {
            $run = WorkflowRun::find($this->runId);
            if ($run === null || $workflowKey === '') {
                return;
            }
            app($workflowKey)->onFailed($run, $exception);
        } catch (Throwable) {
        }
    }

    private function hydrateWorkflow(WorkflowRun $run): CompiledWorkflow
    {
        if ($run->key === null) {
            throw new \RuntimeException("WorkflowRun [{$this->runId}] has no workflow class key.");
        }

        return app($run->key)->compile();
    }
}
