<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Illuminate\Support\Facades\Event;

/**
 * Event dispatch node — fires a Laravel event with data from state.
 */
final class NotifyNode implements Node
{
    /**
     * @param  string  $eventClass  Fully-qualified class name of the event to dispatch.
     * @param  string[]  $dataKeys  State keys to pass as positional constructor arguments.
     */
    public function __construct(
        public readonly string $eventClass,
        public readonly array $dataKeys = [],
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $args = array_map(fn (string $key) => $state[$key] ?? null, $this->dataKeys);
        Event::dispatch(new $this->eventClass(...$args));

        return [];
    }
}
