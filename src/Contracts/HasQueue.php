<?php

namespace Cainy\Laragraph\Contracts;

interface HasQueue
{
    /**
     * The queue name this node's job should be dispatched to.
     */
    public function queue(): string;

    /**
     * The queue connection this node's job should use, or null to use the default.
     */
    public function connection(): ?string;
}
