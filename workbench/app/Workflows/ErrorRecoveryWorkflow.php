<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\FlakyNode;
use Workbench\App\Nodes\SuccessNode;

class ErrorRecoveryWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('flaky-node', FlakyNode::class)
            ->addNode('success-node', SuccessNode::class)
            ->transition(Workflow::START, 'flaky-node')
            ->transition('flaky-node', 'success-node')
            ->transition('success-node', Workflow::END);
    }
}
