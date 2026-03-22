<?php

namespace Workbench\App\Nodes\SafePublisher;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class PublishNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(200_000); // Simulate API call

        $draft = $state['draft'] ?? '(no draft)';

        return [
            'published'    => true,
            'published_at' => now()->toISOString(),
            'tweet'        => $draft,
            'messages'     => [
                ['role' => 'assistant', 'content' => "Published: {$draft}"],
            ],
        ];
    }
}
