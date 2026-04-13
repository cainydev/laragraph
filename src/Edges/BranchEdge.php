<?php

namespace Cainy\Laragraph\Edges;

class BranchEdge
{
    /**
     * @param  string[]  $targets  Possible destination node names (used for visualization).
     */
    public function __construct(
        public readonly string $from,
        public readonly \Closure $resolver,
        public readonly array $targets = [],
    ) {}

    /**
     * @return string[]
     */
    public function resolve(array $state): array
    {
        $result = ($this->resolver)($state);

        return is_array($result) ? $result : [(string) $result];
    }
}
