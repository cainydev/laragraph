<?php

namespace Cainy\Laragraph\Routing;

/**
 * Send API — dispatch a node with an isolated payload (map-reduce / dynamic fan-out).
 *
 * Usage in a BranchEdge resolver:
 *   return array_map(fn ($url) => new Send('fetch_url', ['url' => $url]), $state['urls']);
 *
 * Or via the send_all() ExpressionLanguage helper:
 *   send_all('fetch_url', state['urls'], 'url')
 */
final class Send
{
    public function __construct(
        public readonly string $nodeName,
        public readonly array $payload,
    ) {}
}
