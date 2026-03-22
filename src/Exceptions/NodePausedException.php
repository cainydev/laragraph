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
    ) {
        parent::__construct("Workflow paused at node [{$nodeName}].");
    }
}
