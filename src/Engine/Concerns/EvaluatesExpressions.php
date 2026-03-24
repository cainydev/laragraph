<?php

namespace Cainy\Laragraph\Engine\Concerns;

use Cainy\Laragraph\Routing\Send;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

trait EvaluatesExpressions
{
    protected function makeExpressionLanguage(): ExpressionLanguage
    {
        $el = new ExpressionLanguage;

        // last(array) — last element of an array
        $el->addFunction(new ExpressionFunction(
            'last',
            fn ($arr) => "(empty({$arr}) ? null : end({$arr}))",
            function (array $args, array $arr): mixed {
                return empty($arr) ? null : end($arr);
            },
        ));

        // first(array) — first element of an array
        $el->addFunction(new ExpressionFunction(
            'first',
            fn ($arr) => "(empty({$arr}) ? null : reset({$arr}))",
            function (array $args, array $arr): mixed {
                return empty($arr) ? null : reset($arr);
            },
        ));

        // count(array) — number of elements
        $el->addFunction(new ExpressionFunction(
            'count',
            fn ($arr) => "count({$arr})",
            function (array $args, array $arr): int {
                return count($arr);
            },
        ));

        // empty(value) — null, [], "", false all count as empty
        // Compiler wraps in a closure call to avoid EL re-parsing the `empty` keyword as another function call
        $el->addFunction(new ExpressionFunction(
            'empty',
            fn ($value) => "(static function(\$v): bool { return empty(\$v); })({$value})",
            function (array $args, mixed $value): bool {
                return empty($value);
            },
        ));

        // not_empty(value) — negation of empty
        $el->addFunction(new ExpressionFunction(
            'not_empty',
            fn ($value) => "(static function(\$v): bool { return !empty(\$v); })({$value})",
            function (array $args, mixed $value): bool {
                return ! empty($value);
            },
        ));

        // get(array, path, default) — dot-notation safe access: get(state, "meta.score", 0)
        $el->addFunction(new ExpressionFunction(
            'get',
            fn ($arr, $path, $default = 'null') => "data_get({$arr}, {$path}, {$default})",
            function (array $args, array $arr, string $path, mixed $default = null): mixed {
                $keys = explode('.', $path);
                $current = $arr;

                foreach ($keys as $key) {
                    if (! is_array($current) || ! array_key_exists($key, $current)) {
                        return $default;
                    }
                    $current = $current[$key];
                }

                return $current;
            },
        ));

        // has_value(array, value) — in-array check
        $el->addFunction(new ExpressionFunction(
            'has_value',
            fn ($arr, $value) => "in_array({$value}, {$arr})",
            function (array $args, array $arr, mixed $value): bool {
                return in_array($value, $arr);
            },
        ));

        // keys(array) — array keys
        $el->addFunction(new ExpressionFunction(
            'keys',
            fn ($arr) => "array_keys({$arr})",
            function (array $args, array $arr): array {
                return array_keys($arr);
            },
        ));

        // sum(array) — sum numeric values
        $el->addFunction(new ExpressionFunction(
            'sum',
            fn ($arr) => "array_sum({$arr})",
            function (array $args, array $arr): int|float {
                return array_sum($arr);
            },
        ));

        // join(array, sep) — implode
        $el->addFunction(new ExpressionFunction(
            'join',
            fn ($arr, $sep) => "implode({$sep}, {$arr})",
            function (array $args, array $arr, string $sep): string {
                return implode($sep, $arr);
            },
        ));

        // each(node, items, key) — fan-out Send objects for dynamic routing
        $el->addFunction(new ExpressionFunction(
            'each',
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
