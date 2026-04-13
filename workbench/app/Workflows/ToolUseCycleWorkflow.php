<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\DemoAgentNode;
use Workbench\App\Nodes\SummarizeNode;

class ToolUseCycleWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('agent', new DemoAgentNode)
            ->addNode('summarize', SummarizeNode::class);

        $this->transition(Workflow::START, 'agent')
            ->transition('agent', 'summarize')
            ->transition('summarize', Workflow::END);
    }
}
