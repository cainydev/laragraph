<?php

namespace Cainy\Laragraph\Edges;

use Cainy\Laragraph\Engine\Concerns\EvaluatesExpressions;

readonly class Edge
{
    use EvaluatesExpressions;
    public function __construct(
        public string               $from,
        public string               $to,
        public \Closure|string|null $when = null,
    ) {}

    public function evaluate(array $state): bool
    {
        if ($this->when === null) {
            return true;
        }

        if ($this->when instanceof \Closure) {
            return (bool) ($this->when)($state);
        }

        return (bool) $this->makeExpressionLanguage()->evaluate($this->when, ['state' => $state]);
    }

    public function isSerializable(): bool
    {
        return ! ($this->when instanceof \Closure);
    }

    public function toArray(): array
    {
        if (! $this->isSerializable()) {
            throw new \RuntimeException("Cannot serialize Edge [{$this->from} → {$this->to}]: 'when' is a Closure.");
        }

        return [
            'type' => 'edge',
            'from' => $this->from,
            'to'   => $this->to,
            'when' => $this->when,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['from'], $data['to'], $data['when'] ?? null);
    }
}
