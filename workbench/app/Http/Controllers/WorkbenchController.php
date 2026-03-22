<?php

namespace Workbench\App\Http\Controllers;

use Cainy\Laragraph\Engine\WorkflowRegistry;
use Cainy\Laragraph\Facades\Laragraph;
use Cainy\Laragraph\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    private const array WORKFLOW_META = [
        'linear-chain' => [
            'label'       => 'Linear Chain',
            'description' => 'Three nodes executed sequentially. Each appends a log entry to state.',
            'requiresAI'  => false,
        ],
        'conditional-branch' => [
            'label'       => 'Conditional Branch',
            'description' => 'Classify node sets a random score. Score > 50 routes to approve, otherwise reject.',
            'requiresAI'  => false,
        ],
        'fan-out-fan-in' => [
            'label'       => 'Fan-out / Fan-in',
            'description' => 'Split dispatches two branches in parallel. Merge waits for both results.',
            'requiresAI'  => false,
        ],
        'tool-use-cycle' => [
            'label'       => 'Tool Use Cycle',
            'description' => 'Agent simulates a tool call, tool executor runs it, summarize collects the result.',
            'requiresAI'  => false,
        ],
        'error-recovery' => [
            'label'       => 'Error Recovery',
            'description' => 'Flaky node throws on first attempt and recovers on retry. Demonstrates queue-level retries.',
            'requiresAI'  => false,
        ],
        'deep-researcher' => [
            'label'       => 'Deep Researcher',
            'description' => 'Planner breaks a topic into sub-queries, fans out a parallel worker per query via the Send API, then a compiler merges all findings.',
            'requiresAI'  => false,
        ],
        'safe-publisher' => [
            'label'       => 'Safe Publisher',
            'description' => 'AI drafts a tweet, then pauses for human review. Resume with {meta: {approved: true}} to publish, or {meta: {feedback: "..."}} to redraft.',
            'requiresAI'  => false,
        ],
        'software-factory' => [
            'label'       => 'Software Factory',
            'description' => 'Supervisor agent routes between a Coder and a Reviewer in a loop until the code is approved and the supervisor returns FINISH.',
            'requiresAI'  => false,
        ],
    ];

    public function __construct(private readonly WorkflowRegistry $registry) {}

    public function workflowIndex(): Response
    {
        return Inertia::render('WorkflowIndex', [
            'workflows' => $this->buildWorkflowList(),
        ]);
    }

    public function workflowDetail(string $name): Response
    {
        $workflows = $this->buildWorkflowList();
        $workflow  = collect($workflows)->firstWhere('name', $name);

        abort_if($workflow === null, 404);

        return Inertia::render('WorkflowDetail', [
            'workflow' => $workflow,
        ]);
    }

    public function runDetail(string $id): Response
    {
        /** @var WorkflowRun $run */
        $run = WorkflowRun::findOrFail($id);

        $workflows = $this->buildWorkflowList();
        $workflow  = collect($workflows)->firstWhere('name', $run->key);

        return Inertia::render('RunDetail', [
            'run' => [
                'id'              => $run->id,
                'key'             => $run->key,
                'status'          => $run->status->value,
                'state'           => $run->state,
                'active_pointers' => $run->active_pointers,
                'error'           => $run->state['error'] ?? null,
            ],
            'workflow' => $workflow,
        ]);
    }

    public function run(string $name): JsonResponse
    {
        $run = Laragraph::start($name, []);

        return response()->json(['runId' => $run->id]);
    }

    public function runStatus(string $id): JsonResponse
    {
        $run = WorkflowRun::findOrFail($id);

        return response()->json([
            'id'              => $run->id,
            'key'             => $run->key,
            'status'          => $run->status->value,
            'state'           => $run->state,
            'active_pointers' => $run->active_pointers,
            'error'           => $run->state['error'] ?? null,
        ]);
    }

    public function pauseRun(string $id): JsonResponse
    {
        $run = Laragraph::pause((int) $id);

        return response()->json(['status' => $run->status->value]);
    }

    public function abortRun(string $id): JsonResponse
    {
        $run = Laragraph::abort((int) $id);

        return response()->json(['status' => $run->status->value]);
    }

    public function resumeRun(string $id, Request $request): JsonResponse
    {
        $additionalState = $request->isJson() ? ($request->json()->all() ?? []) : [];
        $run             = Laragraph::resume((int) $id, $additionalState);

        return response()->json(['status' => $run->status->value]);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildWorkflowList(): array
    {
        $workflows = [];

        foreach (self::WORKFLOW_META as $name => $meta) {
            try {
                $compiled = $this->registry->resolve($name);
                $nodes    = array_keys($compiled->getNodes());
                $edges = [];
                foreach ($compiled->getEdges() as $edge) {
                    try {
                        $edges[] = $edge->toArray();
                    } catch (\Throwable) {
                        // Skip edges that cannot be represented (Closure branch with no targets)
                    }
                }
            } catch (\Throwable) {
                $nodes = [];
                $edges = [];
            }

            $workflows[] = array_merge(['name' => $name], $meta, [
                'nodes' => $nodes,
                'edges' => $edges,
            ]);
        }

        return $workflows;
    }
}
