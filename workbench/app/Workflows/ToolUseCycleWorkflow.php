<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\DemoAgentNode;
use Workbench\App\Nodes\DemoToolNode;
use Workbench\App\Nodes\SummarizeNode;

class ToolUseCycleWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('agent', DemoAgentNode::class)
            ->addNode('tool-executor', DemoToolNode::class)
            ->addNode('summarize', SummarizeNode::class)
            ->transition(Workflow::START, 'agent')
            ->transition('agent', 'tool-executor')
            ->transition('tool-executor', 'summarize')
            ->transition('summarize', Workflow::END);
    }
}
