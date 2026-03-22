<?php

namespace Workbench\App\Nodes\DeepResearch;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class CompilerNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(300_000); // Simulate LLM latency

        $findings = $state['findings'] ?? [];
        $expectedCount = count($state['queries'] ?? []);

        // Fan-in barrier: wait until all workers have written their findings
        if (count($findings) < $expectedCount) {
            // Not all workers have finished yet — return empty mutation so this
            // invocation is a no-op. The next worker to complete will re-dispatch.
            return [];
        }

        $report = "# Research Report\n\n" . implode("\n\n", array_map(
            fn ($i, $f) => "## Finding " . ($i + 1) . "\n{$f}",
            array_keys($findings),
            $findings,
        ));

        return [
            'report'   => $report,
            'messages' => [
                ['role' => 'assistant', 'content' => $report],
            ],
        ];
    }
}
