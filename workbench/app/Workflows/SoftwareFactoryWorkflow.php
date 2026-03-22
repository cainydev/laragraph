<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\SoftwareFactory\CoderNode;
use Workbench\App\Nodes\SoftwareFactory\ReviewerNode;
use Workbench\App\Nodes\SoftwareFactory\SupervisorNode;

class SoftwareFactoryWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('supervisor', SupervisorNode::class)
            ->addNode('coder', CoderNode::class)
            ->addNode('reviewer', ReviewerNode::class)
            ->transition(Workflow::START, 'supervisor')
            ->branch('supervisor', function (array $state): string {
                $decision = $state['decision'] ?? '';

                if ($decision === 'FINISH') {
                    return Workflow::END;
                }

                if ($decision === 'ROUTING: CODER') {
                    return 'coder';
                }

                return 'reviewer';
            }, targets: ['coder', 'reviewer', Workflow::END])
            ->transition('coder', 'supervisor')
            ->transition('reviewer', 'supervisor');
    }
}
