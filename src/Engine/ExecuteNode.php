<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Contracts\HasRetryPolicy;
use Cainy\Laragraph\Contracts\HasTags;
use Cainy\Laragraph\Contracts\HasTimeout;
use Cainy\Laragraph\Engine\Concerns\EvaluatesExpressions;
use Cainy\Laragraph\Engine\Concerns\ManagesState;
use Cainy\Laragraph\Engine\Concerns\TracksPointers;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\NodeCompleted;
use Cainy\Laragraph\Events\NodeExecuting;
use Cainy\Laragraph\Events\NodeFailed;
use Cainy\Laragraph\Events\WorkflowCompleted;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Exceptions\NodeExecutionException;
use Cainy\Laragraph\Exceptions\NodePausedException;
use Cainy\Laragraph\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Cainy\Laragraph\Routing\Send;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JsonException;
use Throwable;

class ExecuteNode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use EvaluatesExpressions, ManagesState, TracksPointers;

    public int $tries;

    public int $timeout;

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

    public function backoff(): array
    {
        return $this->backoffIntervals;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        /** @var array<string|Send> $nextTargets */
        $nextTargets = [];
        $completed = false;
        $parentRunId = null;
        $parentNodeName = null;

        DB::transaction(function () use (&$nextTargets, &$completed, &$parentRunId, &$parentNodeName): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            if ($run->status === RunStatus::Failed || $run->status === RunStatus::Paused) {
                return;
            }

            $workflow = $this->hydrateWorkflow($run);
            $reducer = $workflow->getReducer();

            $interruptMarker = $run->state['__interrupt'] ?? null;
            $resumingAfter = $interruptMarker === $this->nodeName
                && $workflow->shouldInterruptAfter($this->nodeName);

            // If resuming from interrupt_after, the node already ran — skip to edges.
            if ($resumingAfter) {
                $newState = $run->state;
                unset($newState['__interrupt']);
            } else {
                $node = $workflow->resolveNode($this->nodeName);

                // Apply per-node contract overrides
                if ($node instanceof HasTimeout) {
                    $this->timeout = $node->timeout();
                }
                if ($node instanceof HasRetryPolicy) {
                    $policy = $node->retryPolicy();
                    $this->tries = $policy->maxAttempts;
                    $this->backoffIntervals = $policy->calculateBackoff();
                }

                $resumingBefore = $interruptMarker === $this->nodeName
                    && $workflow->shouldInterruptBefore($this->nodeName);

                // interrupt_before: pause BEFORE node runs (unless resuming from this interrupt)
                if (! $resumingBefore && $workflow->shouldInterruptBefore($this->nodeName)) {
                    $run->state = array_merge($run->state, ['__interrupt' => $this->nodeName]);
                    $run->status = RunStatus::Paused;
                    $run->save();

                    return;
                }

                Event::dispatch(new NodeExecuting($this->runId, $this->nodeName));

                $context = NodeExecutionContext::fromJob($run, $this->nodeName, $this->attempts(), $this->tries, $this->isolatedPayload);

                try {
                    $mutation = $node->handle($context, $run->state);
                } catch (NodePausedException $e) {
                    // Apply any state the node wants to persist (e.g. child run ID) before pausing.
                    $pauseState = array_merge($run->state, $e->stateMutation, ['__interrupt' => $this->nodeName]);
                    $run->state = $pauseState;
                    $run->status = RunStatus::Paused;
                    $run->save();

                    return;
                } catch (Throwable $e) {
                    throw new NodeExecutionException($this->nodeName, $this->runId, previous: $e);
                }

                $newState = $this->applyMutation($run, $mutation, $reducer);
                unset($newState['__interrupt']);
                $run->state = $newState;

                $tags = $node instanceof HasTags ? $node->tags() : [];
                Event::dispatch(new NodeCompleted($this->runId, $this->nodeName, $mutation, $tags));

                // interrupt_after: pause AFTER node runs but BEFORE edges evaluate
                if ($workflow->shouldInterruptAfter($this->nodeName)) {
                    $newState['__interrupt'] = $this->nodeName;
                    $run->state = $newState;
                    $run->current = $this->nodeName;
                    $run->status = RunStatus::Paused;
                    $run->save();

                    return;
                }
            }

            $nextTargets = $workflow->resolveNextNodes($this->nodeName, $newState);

            // Separate plain node names from Send objects; filter out END
            $nextNodeNames = array_values(array_filter(
                $nextTargets,
                fn ($t) => ! ($t instanceof Send) && $t !== Workflow::END,
            ));

            $this->removePointer($run, $this->nodeName);
            if (! empty($nextNodeNames)) {
                $this->pushPointers($run, ...$nextNodeNames);
            }

            // Send objects contribute pointers too
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
        });

        if ($completed) {
            Event::dispatch(new WorkflowCompleted($this->runId));

            // If this is a child workflow, resume the waiting parent node.
            if ($parentRunId !== null && $parentNodeName !== null) {
                app(Laragraph::class)->resumeFromChild($parentRunId, $parentNodeName);
            }

            return;
        }

        $freshStatus = WorkflowRun::find($this->runId)?->status;
        if ($freshStatus !== RunStatus::Running) {
            return;
        }

        foreach ($nextTargets as $target) {
            if ($target instanceof Send) {
                static::dispatch($this->runId, $target->nodeName, $target->payload);
            } elseif ($target !== Workflow::END) {
                static::dispatch($this->runId, $target);
            }
        }
    }

    /**
     * @throws Throwable
     */
    public function failed(Throwable $exception): void
    {
        $root = $exception->getPrevious() ?? $exception;

        DB::transaction(function () use ($root): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            // If the run already completed/failed via a successful retry, don't overwrite it.
            if ($run->status === RunStatus::Completed || $run->status === RunStatus::Failed) {
                return;
            }

            $reducer = $this->hydrateWorkflow($run)->getReducer();
            $this->applyMutation($run, [
                'error' => [
                    'node' => $this->nodeName,
                    'message' => $root->getMessage(),
                    'file' => $root->getFile(),
                    'line' => $root->getLine(),
                ],
            ], $reducer);

            $this->removePointer($run, $this->nodeName);

            $run->status = RunStatus::Failed;
            $run->save();
        });

        Event::dispatch(new NodeFailed($this->runId, $this->nodeName, $root));
        Event::dispatch(new WorkflowFailed($this->runId, $root));
    }

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     * @throws JsonException
     */
    private function hydrateWorkflow(WorkflowRun $run): CompiledWorkflow
    {
        if ($run->snapshot !== null) {
            $json = json_encode($run->snapshot, JSON_THROW_ON_ERROR);

            return Workflow::fromJson($json);
        }

        if ($run->key !== null) {
            return app(WorkflowRegistry::class)->resolve($run->key);
        }

        throw new \RuntimeException("WorkflowRun [{$this->runId}] has neither a snapshot nor a registry key.");
    }
}
