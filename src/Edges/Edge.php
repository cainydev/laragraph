<?php

namespace Cainy\Laragraph\Edges;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class Edge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly \Closure|string|null $when = null,
    ) {}

    public function evaluate(array $state): bool
    {
        if ($this->when === null) {
            return true;
        }

        if ($this->when instanceof \Closure) {
            return (bool) ($this->when)($state);
        }

        $el = new ExpressionLanguage();

        return (bool) $el->evaluate($this->when, ['state' => $state]);
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
