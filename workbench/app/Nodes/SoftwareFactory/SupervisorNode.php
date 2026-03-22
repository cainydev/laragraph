<?php

namespace Workbench\App\Nodes\SoftwareFactory;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class SupervisorNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(300_000); // Simulate LLM latency

        $iteration = ($state['iteration'] ?? 0) + 1;
        $code       = $state['code'] ?? null;
        $review     = $state['review'] ?? null;

        // Simulate supervisor decision logic
        if ($iteration === 1) {
            $decision = 'ROUTING: CODER';
            $message  = 'ROUTING: CODER — Please write a PHP function to calculate fibonacci numbers.';
        } elseif ($iteration === 2 && $code) {
            $decision = 'ROUTING: REVIEWER';
            $message  = 'ROUTING: REVIEWER — Please review the submitted code for correctness and edge cases.';
        } else {
            $decision = 'FINISH';
            $message  = 'FINISH — The code looks good. Task complete.';
        }

        return [
            'iteration' => $iteration,
            'decision'  => $decision,
            'messages'  => [
                ['role' => 'assistant', 'content' => $message],
            ],
        ];
    }
}
