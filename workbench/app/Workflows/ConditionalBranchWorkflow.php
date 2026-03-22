<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\ApproveNode;
use Workbench\App\Nodes\ClassifyNode;
use Workbench\App\Nodes\RejectNode;

class ConditionalBranchWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('classify', ClassifyNode::class)
            ->addNode('approve', ApproveNode::class)
            ->addNode('reject', RejectNode::class)
            ->transition(Workflow::START, 'classify')
            ->transition('classify', 'approve', "state['score'] > 50")
            ->transition('classify', 'reject', "state['score'] <= 50")
            ->transition('approve', Workflow::END)
            ->transition('reject', Workflow::END);
    }
}
