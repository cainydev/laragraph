<?php

namespace Cainy\Laragraph;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Engine\ExecuteNodeJob;
use Cainy\Laragraph\Engine\PointerTracker;
use Cainy\Laragraph\Engine\WorkflowRegistry;
use Cainy\Laragraph\Enums\RunStatus;
use Cainy\Laragraph\Events\WorkflowStarted;
use Cainy\Laragraph\Models\WorkflowRun;
use Illuminate\Support\Facades\Event;

class Laragraph
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly PointerTracker $pointerTracker,
    ) {}

    public function start(string $workflowName, array $initialState = [], ?string $key = null): WorkflowRun
    {
        $workflow = $this->registry->resolve($workflowName);

        $run = WorkflowRun::create([
            'key'    => $key ?? $workflowName,
            'state'  => $initialState,
            'status' => RunStatus::Running,
        ]);

        $this->pointerTracker->push($run, ...$workflow->getStartNodes());
        $run->save();

        Event::dispatch(new WorkflowStarted($run->id, $workflowName));

        foreach ($workflow->getStartNodes() as $node) {
            ExecuteNodeJob::dispatch($run->id, $node);
        }

        return $run;
    }

    public function startFromBlueprint(Workflow $blueprint, array $initialState = [], ?string $key = null): WorkflowRun
    {
        $snapshot = $blueprint->toJson();
        $compiled = Workflow::fromJson($snapshot);

        $run = WorkflowRun::create([
            'key'      => $key,
            'snapshot' => json_decode($snapshot, true),
            'state'    => $initialState,
            'status'   => RunStatus::Running,
        ]);

        $startNodes = $compiled->getStartNodes();
        $this->pointerTracker->push($run, ...$startNodes);
        $run->save();

        Event::dispatch(new WorkflowStarted($run->id, $key ?? 'blueprint'));

        foreach ($startNodes as $node) {
            ExecuteNodeJob::dispatch($run->id, $node);
        }

        return $run;
    }

    public function resume(int $runId, array $additionalState = []): WorkflowRun
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::findOrFail($runId);

        if (! empty($additionalState)) {
            $run->state = array_merge($run->state, $additionalState);
            $run->save();
        }

        $run->status = RunStatus::Running;
        $run->save();

        $pointers = $run->active_pointers ?? [];

        foreach ($pointers as $nodeName) {
            ExecuteNodeJob::dispatch($runId, $nodeName);
        }

        return $run;
    }
}
