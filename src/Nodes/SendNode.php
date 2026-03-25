<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Cainy\Laragraph\Routing\Send;

/**
 * Send node — dispatches a Send for each item in a state list.
 */
final class SendNode implements SerializableNode
{
    public function __construct(
        public readonly string $sourceKey,   // state key containing the list to iterate
        public readonly string $targetNode,  // node name to send to
        public readonly string $payloadKey,  // key name for each item in the Send payload
    ) {}

    /**
     * @return Send[]
     */
    public function handle(NodeExecutionContext $context, array $state): array
    {
        $items = $state[$this->sourceKey] ?? [];

        return array_map(
            fn ($item) => new Send($this->targetNode, [$this->payloadKey => $item]),
            $items,
        );
    }

    public function toArray(): array
    {
        return [
            '__synthetic' => 'send',
            'source_key' => $this->sourceKey,
            'target_node' => $this->targetNode,
            'payload_key' => $this->payloadKey,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            sourceKey: $data['source_key'],
            targetNode: $data['target_node'],
            payloadKey: $data['payload_key'],
        );
    }
}
