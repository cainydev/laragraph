<?php

namespace Cainy\Laragraph\Routing;

use Cainy\Laragraph\Builder\Workflow;

/**
 * Send — dispatch a node with an isolated payload.
 *
 * Usage in a BranchEdge resolver:
 *   return array_map(fn ($url) => new Send('fetch_url', ['url' => $url]), $state['urls']);
 *
 * Or via the SendNode prebuilt.
 */
final class Send
{
    public function __construct(
        public readonly string $nodeName,
        public readonly array $payload,
    ) {}

    /**
     * Sugar for dispatching to a sub-workflow embedded at `$nodeName`. The
     * payload becomes the child workflow's initial state — so each Send spawns
     * an independent child run with its own state bag. Use for per-item
     * pipelines after a fan-out.
     *
     *   ->branch('fanout', fn ($state) => collect($state['ids'])->map(
     *       fn ($id) => Send::toWorkflow('lead_pipeline', ['lead_id' => $id])
     *   )->all())
     *
     * Note: the target node must be a Workflow instance/class added via
     * addNode('lead_pipeline', app(LeadPipelineWorkflow::class)).
     */
    public static function toWorkflow(string $nodeName, array $initialState): self
    {
        if ($nodeName === Workflow::START || $nodeName === Workflow::END) {
            throw new \InvalidArgumentException('Send::toWorkflow target must be a user-defined node name.');
        }

        return new self($nodeName, $initialState);
    }
}
