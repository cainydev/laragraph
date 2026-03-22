<?php

namespace Cainy\Laragraph\Reducers;

use Cainy\Laragraph\Contracts\StateReducerInterface;

class MergeReducer implements StateReducerInterface
{
    public function reduce(array $currentState, array $mutation): array
    {
        return $this->deepMerge($currentState, $mutation);
    }

    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
