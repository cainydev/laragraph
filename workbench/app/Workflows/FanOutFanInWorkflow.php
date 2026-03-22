<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\BranchANode;
use Workbench\App\Nodes\BranchBNode;
use Workbench\App\Nodes\MergeNode;
use Workbench\App\Nodes\SplitNode;

class FanOutFanInWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('split', SplitNode::class)
            ->addNode('branch-a', BranchANode::class)
            ->addNode('branch-b', BranchBNode::class)
            ->addNode('merge', MergeNode::class)
            ->transition(Workflow::START, 'split')
            ->transition('split', 'branch-a')
            ->transition('split', 'branch-b')
            ->transition('branch-a', 'merge')
            ->transition('branch-b', 'merge')
            ->transition('merge', Workflow::END);
    }
}
