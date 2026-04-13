<?php

namespace Cainy\Laragraph\Contracts;

interface HasMiddleware
{
    /**
     * Job middleware to apply when this node's ExecuteNode job is processed.
     * Return standard Laravel job middleware objects, e.g. RateLimited, WithoutOverlapping.
     *
     * @return array<object>
     */
    public function middleware(): array;
}
