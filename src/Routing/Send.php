<?php

namespace Cainy\Laragraph\Routing;

/**
 * Send — dispatch a node with an isolated payload.
 *
 * Usage in a BranchEdge resolver:
 *   return array_map(fn ($url) => new Send('fetch_url', ['url' => $url]), $state['urls']);
 *
 * Or via the SendNode prebuilt or send() ExpressionLanguage helper.
 */
final class Send
{
    public function __construct(
        public readonly string $nodeName,
        public readonly array $payload,
    ) {}
}
