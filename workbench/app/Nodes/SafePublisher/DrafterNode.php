<?php

namespace Workbench\App\Nodes\SafePublisher;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class DrafterNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(400_000); // Simulate LLM latency

        $feedback = $state['meta']['feedback'] ?? null;
        $attempt  = ($state['draft_attempt'] ?? 0) + 1;

        $drafts = [
            "🚀 Excited to announce our latest product launch! Innovation meets simplicity. #Tech #Launch",
            "✨ We've been working on something big. Today, we share it with the world. Stay tuned! #Innovation",
            "💡 The future is here. Our new product changes everything you thought you knew. #Disruption",
        ];

        $draft = $feedback
            ? "Revised draft (addressing: \"{$feedback}\"): " . $drafts[$attempt % count($drafts)]
            : $drafts[0];

        return [
            'draft'         => $draft,
            'draft_attempt' => $attempt,
            'messages'      => [
                ['role' => 'assistant', 'content' => $draft],
            ],
        ];
    }
}
