<?php

namespace Cainy\Laragraph\Contracts;

interface HasLoop
{
    /**
     * The companion node that handles delegated work inside the loop.
     *
     * @param  string  $nodeName  The graph key of the node that owns this loop.
     */
    public function loopNode(string $nodeName): Node;

    /**
     * Expression string or Closure evaluated against state.
     * When truthy, the loop edge fires instead of the normal exit edges.
     *
     * @return string|\Closure
     */
    public function loopCondition(): string|\Closure;
}
