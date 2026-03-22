<?php

namespace Workbench\App\Workflows;

use Cainy\Laragraph\Builder\Workflow;
use Workbench\App\Nodes\SafePublisher\DrafterNode;
use Workbench\App\Nodes\SafePublisher\PublishNode;
use Workbench\App\Nodes\SafePublisher\ReviewRouterNode;

class SafePublisherWorkflow
{
    public static function build(): Workflow
    {
        return Workflow::create()
            ->addNode('drafter', DrafterNode::class)
            ->addNode('review-router', ReviewRouterNode::class)
            ->addNode('publish', PublishNode::class)
            ->transition(Workflow::START, 'drafter')
            ->transition('drafter', 'review-router')
            // After resume(['meta' => ['approved' => true]]) the review-router decides
            ->branch('review-router', function (array $state): string {
                if ($state['meta']['approved'] ?? false) {
                    return 'publish';
                }

                return 'drafter';
            }, targets: ['publish', 'drafter'])
            ->transition('publish', Workflow::END)
            // Pause after drafter so a human can review the draft
            ->interruptAfter('drafter');
    }
}
