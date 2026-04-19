<?php

namespace Cainy\Laragraph\Exceptions;

/**
 * Thrown by a node to signal that the workflow should pause at this node.
 * The engine catches this, applies any stateMutation atomically, sets
 * status = Paused, and keeps the active pointer intact so resume() can
 * re-dispatch from here.
 */
class NodePausedException extends \RuntimeException
{
    public function __construct(
        string $nodeName,
        public readonly array $stateMutation = [],
        public readonly ?string $gateReason = null,
        public readonly ?int $childRunId = null,
        /** Only persist the child id into parent.routing when true — concurrent
         *  per-item Sends to the same sub-graph node cannot share a routing slot. */
        public readonly bool $childRunIdIsPersistable = true,
    ) {
        parent::__construct("Workflow paused at node [{$nodeName}].");
    }
}
