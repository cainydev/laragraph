<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\DemoAgentNode;
use Workbench\App\Nodes\SummarizeNode;

class ToolUseCycleWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('agent', new DemoAgentNode)
            ->addNode('summarize', SummarizeNode::class)
            ->transition(Workflow::START, 'agent')
            ->transition('agent', 'summarize')
            ->transition('summarize', Workflow::END);
    }
}
