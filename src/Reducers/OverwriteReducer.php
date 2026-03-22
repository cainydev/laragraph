<?php

namespace Cainy\Laragraph\Reducers;

use Cainy\Laragraph\Contracts\StateReducerInterface;

class OverwriteReducer implements StateReducerInterface
{
    public function reduce(array $currentState, array $mutation): array
    {
        return array_merge($currentState, $mutation);
    }
}
