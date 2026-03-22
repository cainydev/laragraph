<?php

namespace Cainy\Laragraph\Edges;

use Cainy\Laragraph\Engine\Concerns\EvaluatesExpressions;

class BranchEdge
{
    use EvaluatesExpressions;

    /**
     * @param  string[]  $targets  Possible destination node names (used for visualization).
     */
    public function __construct(
        public readonly string $from,
        public readonly \Closure|string $resolver,
        public readonly array $targets = [],
    ) {}

    /**
     * @return string[]
     */
    public function resolve(array $state): array
    {
        if ($this->resolver instanceof \Closure) {
            $result = ($this->resolver)($state);
        } else {
            $result = $this->makeExpressionLanguage()->evaluate($this->resolver, ['state' => $state]);
        }

        return is_array($result) ? $result : [(string) $result];
    }

    public function isSerializable(): bool
    {
        return ! ($this->resolver instanceof \Closure);
    }

    public function toArray(): array
    {
        if ($this->isSerializable()) {
            return [
                'type' => 'branch',
                'from' => $this->from,
                'resolver' => $this->resolver,
                'targets' => $this->targets,
            ];
        }

        // Closure resolvers cannot be serialized, but targets are enough for visualization.
        if (empty($this->targets)) {
            throw new \RuntimeException(
                "Cannot serialize BranchEdge [{$this->from}]: 'resolver' is a Closure and no 'targets' were declared. ".
                'Pass targets to ->branch() so the graph can be visualized.'
            );
        }

        return [
            'type' => 'branch',
            'from' => $this->from,
            'targets' => $this->targets,
        ];
    }

    public static function fromArray(array $data): self
    {
        // Closure-based edges are never reconstructed from JSON (snapshot workflows use string resolvers).
        // If 'resolver' is absent this is a visualization-only branch edge.
        return new self(
            $data['from'],
            $data['resolver'] ?? '',
            $data['targets'] ?? [],
        );
    }
}
