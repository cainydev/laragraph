<?php

namespace Cainy\Laragraph\Edges;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class BranchEdge
{
    public function __construct(
        public readonly string $from,
        public readonly \Closure|string $resolver,
    ) {}

    /**
     * @return string[]
     */
    public function resolve(array $state): array
    {
        if ($this->resolver instanceof \Closure) {
            $result = ($this->resolver)($state);
        } else {
            $el = new ExpressionLanguage();
            $result = $el->evaluate($this->resolver, ['state' => $state]);
        }

        return is_array($result) ? $result : [(string) $result];
    }

    public function isSerializable(): bool
    {
        return ! ($this->resolver instanceof \Closure);
    }

    public function toArray(): array
    {
        if (! $this->isSerializable()) {
            throw new \RuntimeException("Cannot serialize BranchEdge [{$this->from}]: 'resolver' is a Closure.");
        }

        return [
            'type'     => 'branch',
            'from'     => $this->from,
            'resolver' => $this->resolver,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['from'], $data['resolver']);
    }
}
