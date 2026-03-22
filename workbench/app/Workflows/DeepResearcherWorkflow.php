<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Routing\Send;
use Workbench\App\Nodes\DeepResearch\CompilerNode;
use Workbench\App\Nodes\DeepResearch\PlannerNode;
use Workbench\App\Nodes\DeepResearch\ResearchWorkerNode;

class DeepResearcherWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('planner', PlannerNode::class)
            ->addNode('research-worker', ResearchWorkerNode::class)
            ->addNode('compiler', CompilerNode::class)
            ->transition(Workflow::START, 'planner')
            // Dynamic fan-out: spin up one research-worker job per query via Send API
            ->branch('planner', function (array $state): array {
                return array_map(
                    fn (string $query) => new Send('research-worker', ['search_query' => $query]),
                    $state['queries'] ?? [],
                );
            }, targets: ['research-worker'])
            // All workers converge on compiler (fan-in barrier handled inside CompilerNode)
            ->transition('research-worker', 'compiler')
            ->transition('compiler', Workflow::END);
    }
}
