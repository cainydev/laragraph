<?php

namespace Cainy\Laragraph\Exceptions;

/**
 * Thrown by a node to signal that this execution should be silently skipped —
 * the pointer is removed and no edges are evaluated. The run stays active as
 * long as other pointers remain.
 *
 * Used by ReduceNode to absorb early fan-in arrivals without pausing the run.
 */
class NodeSkippedException extends \RuntimeException
{
    public function __construct(public readonly string $nodeName)
    {
        parent::__construct("Node [{$nodeName}] skipped.");
    }
}
