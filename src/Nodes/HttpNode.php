<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\Node;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Illuminate\Support\Facades\Http;

/**
 * HTTP request node — makes an HTTP call and stores the response in state.
 * URL and body key values support {state.key} interpolation.
 */
final class HttpNode implements Node
{
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly ?string $bodyKey = null,    // state key for request body
        public readonly string $responseKey = 'response', // state key to store response
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $url = $this->interpolate($this->url, $state);

        $request = Http::withHeaders($this->headers);

        $body = $this->bodyKey !== null ? ($state[$this->bodyKey] ?? []) : [];

        $response = match (strtoupper($this->method)) {
            'POST' => $request->post($url, $body),
            'PUT' => $request->put($url, $body),
            'PATCH' => $request->patch($url, $body),
            'DELETE' => $request->delete($url, $body),
            default => $request->get($url),
        };

        return [
            $this->responseKey => [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'ok' => $response->successful(),
            ],
        ];
    }

    /**
     * Replace {state.key} and {state.nested.key} placeholders with values from state.
     */
    private function interpolate(string $template, array $state): string
    {
        return preg_replace_callback('/\{state\.([^}]+)\}/', function (array $matches) use ($state): string {
            $keys = explode('.', $matches[1]);
            $value = $state;

            foreach ($keys as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    return $matches[0];
                }
                $value = $value[$key];
            }

            if (is_array($value)) {
                return '';
            }

            if (is_object($value)) {
                return method_exists($value, '__toString') ? (string) $value : '';
            }

            return (string) $value;
        }, $template) ?? $template;
    }
}
