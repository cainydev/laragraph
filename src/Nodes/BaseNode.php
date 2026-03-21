<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\NodeInterface;

abstract class BaseNode implements NodeInterface
{
    public function getName(): string
    {
        return static::class;
    }

    abstract public function __invoke(int $runId, array $state): array;
}
