<?php

namespace Cainy\Laragraph\Contracts;

interface StateReducerInterface
{
    public function reduce(array $currentState, array $mutation): array;
}
