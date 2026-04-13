<?php

namespace Cainy\Laragraph\Exceptions;

/**
 * Thrown by a node to signal that this execution should be silently skipped —
 * the pointer is removed and no edges are evaluated. The run stays active as
 * long as other pointers remain.
 *
 * Used by custom nodes that need to signal a silent skip without pausing the run.
 */
class NodeSkippedException extends \RuntimeException
{
    public function __construct(public readonly string $nodeName)
    {
        parent::__construct("Node [{$nodeName}] skipped.");
    }
}
