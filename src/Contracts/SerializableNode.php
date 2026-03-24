<?php

namespace Cainy\Laragraph\Contracts;

interface SerializableNode extends Node
{
    /**
     * Serialize to an array that can be stored in workflow JSON.
     * Must include a '__synthetic' key identifying the type.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Reconstruct the node from a serialized array.
     */
    public static function fromArray(array $data): static;
}
