<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\IsFanInBarrier;
use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;

/**
 * Barrier node — waits for all parallel branches to finish before
 * allowing the workflow to continue.
 *
 * Place this node after a fan-out (e.g. after a SendNode) to collect all
 * parallel results before moving on. The engine automatically tracks how many
 * workers were dispatched and only opens the barrier once every one of them
 * has committed its result — no configuration needed.
 *
 *   ->addNode('barrier', new BarrierNode())
 *   ->transition('worker', 'barrier')
 *   ->transition('barrier', 'aggregator')
 */
final class BarrierNode implements IsFanInBarrier, Node
{
    public function handle(NodeExecutionContext $context, array $state): array
    {
        return [];
    }
}
