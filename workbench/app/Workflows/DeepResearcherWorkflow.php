<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Cainy\Laragraph\Routing\Send;
use Workbench\App\Nodes\DeepResearch\CompilerNode;
use Workbench\App\Nodes\DeepResearch\PlannerNode;
use Workbench\App\Nodes\DeepResearch\ResearchWorkerNode;

class DeepResearcherWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('planner', PlannerNode::class)
            ->addNode('research-worker', ResearchWorkerNode::class)
            ->addNode('compiler', CompilerNode::class);

        $this->transition(Workflow::START, 'planner')
            ->branch('planner', function (array $state): array {
                return array_map(
                    fn (string $query) => new Send('research-worker', ['search_query' => $query]),
                    $state['queries'] ?? [],
                );
            }, targets: ['research-worker'])
            ->transition('research-worker', 'compiler')
            ->transition('compiler', Workflow::END);
    }
}
