<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Contracts\StateReducerInterface;

class DefaultStateReducer implements StateReducerInterface
{
    public function reduce(array $currentState, array $mutation): array
    {
        foreach ($mutation as $key => $value) {
            if (
                array_is_list($value)
                && isset($currentState[$key])
                && is_array($currentState[$key])
            ) {
                $currentState[$key] = array_merge($currentState[$key], $value);
            } else {
                $currentState[$key] = $value;
            }
        }

        return $currentState;
    }
}
