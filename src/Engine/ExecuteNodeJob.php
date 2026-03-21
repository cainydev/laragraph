<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\NodeCompleted;
use Cainy\Laragraph\Events\NodeExecuting;
use Cainy\Laragraph\Events\WorkflowCompleted;
use Cainy\Laragraph\Events\WorkflowFailed;
use Cainy\Laragraph\Exceptions\NodeExecutionException;
use Cainy\Laragraph\Models\WorkflowRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ExecuteNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(
        public readonly int $runId,
        public readonly string $nodeName,
    ) {
        $this->tries = config('laragraph.max_node_attempts', 3);
        $this->onQueue(config('laragraph.queue', 'default'));
        $connection = config('laragraph.connection');
        if ($connection !== null) {
            $this->onConnection($connection);
        }
    }

    public function handle(PointerTracker $pointerTracker): void
    {
        $nextNodes = [];
        $completed = false;

        DB::transaction(function () use ($pointerTracker, &$nextNodes, &$completed): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            $workflow = $this->hydrateWorkflow($run);
            $reducer = $workflow->getReducer();
            $node = $workflow->resolveNode($this->nodeName);

            Event::dispatch(new NodeExecuting($this->runId, $this->nodeName));

            try {
                $mutation = $node($this->runId, $run->state);
            } catch (\Throwable $e) {
                throw new NodeExecutionException($this->nodeName, $this->runId, previous: $e);
            }

            $newState = $reducer->reduce($run->state, $mutation);

            Event::dispatch(new NodeCompleted($this->runId, $this->nodeName, $mutation));

            $resolvedNext = $workflow->resolveNextNodes($this->nodeName, $newState);
            $nextNodes = array_values(array_filter($resolvedNext, fn ($n) => $n !== Workflow::END));

            $pointerTracker->remove($run, $this->nodeName);
            if (! empty($nextNodes)) {
                $pointerTracker->push($run, ...$nextNodes);
            }

            $run->state = $newState;
            $run->current = $this->nodeName;

            if ($pointerTracker->isEmpty($run)) {
                $run->status = RunStatus::Completed;
                $completed = true;
            } else {
                $run->status = RunStatus::Running;
            }

            $run->save();
        });

        foreach ($nextNodes as $next) {
            static::dispatch($this->runId, $next);
        }

        if ($completed) {
            Event::dispatch(new WorkflowCompleted($this->runId));
        }
    }

    public function failed(\Throwable $exception): void
    {
        DB::transaction(function (): void {
            /** @var WorkflowRun $run */
            $run = WorkflowRun::lockForUpdate()->findOrFail($this->runId);

            $pointerTracker = app(PointerTracker::class);
            $pointerTracker->remove($run, $this->nodeName);

            $run->status = RunStatus::Failed;
            $run->save();
        });

        Event::dispatch(new WorkflowFailed($this->runId, $exception));
    }

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
