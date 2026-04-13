<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\ApproveNode;
use Workbench\App\Nodes\ClassifyNode;
use Workbench\App\Nodes\RejectNode;

class ConditionalBranchWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('classify', ClassifyNode::class)
            ->addNode('approve', ApproveNode::class)
            ->addNode('reject', RejectNode::class);

        $this->transition(Workflow::START, 'classify')
            ->transition('classify', 'approve', fn (array $s) => ($s['score'] ?? 0) > 50)
            ->transition('classify', 'reject', fn (array $s) => ($s['score'] ?? 0) <= 50)
            ->transition('approve', Workflow::END)
            ->transition('reject', Workflow::END);
    }
}
