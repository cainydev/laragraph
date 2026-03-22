<?php

namespace Workbench\App\Nodes\SoftwareFactory;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

class CoderNode implements Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        usleep(400_000); // Simulate LLM latency

        $code = <<<'PHP'
function fibonacci(int $n): int {
    if ($n <= 1) return $n;
    return fibonacci($n - 1) + fibonacci($n - 2);
}
PHP;

        return [
            'code' => $code,
            'messages' => [
                ['role' => 'assistant', 'content' => "Here is the PHP code:\n```php\n{$code}\n```"],
            ],
        ];
    }
}
