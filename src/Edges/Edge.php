<?php

namespace Cainy\Laragraph\Edges;

readonly class Edge
{
    public function __construct(
        public string $from,
        public string $to,
        public \Closure|null $when = null,
    ) {}

    public function evaluate(array $state): bool
    {
        if ($this->when === null) {
            return true;
        }

        return (bool) ($this->when)($state);
    }
}
