<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\LinearNodeA;
use Workbench\App\Nodes\LinearNodeB;
use Workbench\App\Nodes\LinearNodeC;

class LinearChainWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('node-a', LinearNodeA::class)
            ->addNode('node-b', LinearNodeB::class)
            ->addNode('node-c', LinearNodeC::class);

        $this->transition(Workflow::START, 'node-a')
            ->transition('node-a', 'node-b')
            ->transition('node-b', 'node-c')
            ->transition('node-c', Workflow::END);
    }
}
