<?php

namespace Cainy\Laragraph\Reducers;

use Cainy\Laragraph\Contracts\StateReducerInterface;

class SmartReducer implements StateReducerInterface
{
    public function reduce(array $currentState, array $mutation): array
    {
        foreach ($mutation as $key => $value) {
            if (
                is_array($value)
                && array_is_list($value)
                && isset($currentState[$key])
                && is_array($currentState[$key])
                && array_is_list($currentState[$key])
            ) {
                $currentState[$key] = array_merge($currentState[$key], $value);
            } else {
                $currentState[$key] = $value;
            }
        }

        return $currentState;
    }
}
