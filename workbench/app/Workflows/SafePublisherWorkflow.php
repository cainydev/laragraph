<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\SafePublisher\DrafterNode;
use Workbench\App\Nodes\SafePublisher\PublishNode;
use Workbench\App\Nodes\SafePublisher\ReviewRouterNode;

class SafePublisherWorkflow extends Workflow
{
    public function definition(): void
    {
        $this->addNode('drafter', DrafterNode::class)
            ->addNode('review-router', ReviewRouterNode::class)
            ->addNode('publish', PublishNode::class);

        $this->transition(Workflow::START, 'drafter')
            ->transition('drafter', 'review-router')
            ->branch('review-router', function (array $state): string {
                if ($state['meta']['approved'] ?? false) {
                    return 'publish';
                }

                return 'drafter';
            }, targets: ['publish', 'drafter'])
            ->transition('publish', Workflow::END)
            ->interruptAfter('drafter');
    }
}
