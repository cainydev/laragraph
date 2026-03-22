<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Routing\Send;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

trait EvaluatesExpressions
{
    protected function makeExpressionLanguage(): ExpressionLanguage
    {
        $el = new ExpressionLanguage();

        // has_tool_calls(messages) — true if the last message contains tool_calls
        $el->addFunction(new ExpressionFunction(
            'has_tool_calls',
            fn ($messages) => "(!empty(end({$messages})['tool_calls']))",
            function (array $args, array $messages): bool {
                $last = ! empty($messages) ? end($messages) : null;

                return ! empty($last['tool_calls']);
            },
        ));

        // send_all(nodeName, items, payloadKey) — returns Send[] for dynamic fan-out
        $el->addFunction(new ExpressionFunction(
            'send_all',
            fn ($nodeName, $items, $payloadKey) => sprintf(
                'array_map(fn($item) => new \\Cainy\\Laragraph\\Routing\\Send(%s, [%s => $item]), %s)',
                $nodeName,
                $payloadKey,
                $items,
            ),
            function (array $args, string $nodeName, array $items, string $payloadKey): array {
                return array_map(
                    fn ($item) => new Send($nodeName, [$payloadKey => $item]),
                    $items,
                );
            },
        ));

        return $el;
    }
}
