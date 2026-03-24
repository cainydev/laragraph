<?php

namespace Cainy\Laragraph\Nodes;

use Cainy\Laragraph\Contracts\SerializableNode;
use Cainy\Laragraph\Engine\NodeExecutionContext;
use Illuminate\Support\Facades\Cache;

/**
 * Cache read/write node.
 * The cacheKey supports {state.key} interpolation.
 */
final class CacheNode implements SerializableNode
{
    public function __construct(
        public readonly string $operation,  // 'get', 'put', 'forget'
        public readonly string $cacheKey,   // supports {state.key} interpolation
        public readonly string $stateKey,   // state key to read from / write to
        public readonly ?int $ttl = null,
    ) {}

    public function handle(NodeExecutionContext $context, array $state): array
    {
        $resolvedKey = $this->interpolate($this->cacheKey, $state);

        return match ($this->operation) {
            'get' => [$this->stateKey => Cache::get($resolvedKey)],
            'put' => $this->handlePut($resolvedKey, $state),
            'forget' => $this->handleForget($resolvedKey),
            default => throw new \InvalidArgumentException("Unknown cache operation [{$this->operation}]."),
        };
    }

    private function handlePut(string $resolvedKey, array $state): array
    {
        $value = $state[$this->stateKey] ?? null;

        if ($this->ttl !== null) {
            Cache::put($resolvedKey, $value, $this->ttl);
        } else {
            Cache::forever($resolvedKey, $value);
        }

        return [];
    }

    private function handleForget(string $resolvedKey): array
    {
        Cache::forget($resolvedKey);

        return [];
    }

    /**
     * Replace {state.key} placeholders with values from state.
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

            return (string) $value;
        }, $template) ?? $template;
    }

    public function toArray(): array
    {
        return [
            '__synthetic' => 'cache',
            'operation' => $this->operation,
            'cache_key' => $this->cacheKey,
            'state_key' => $this->stateKey,
            'ttl' => $this->ttl,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            operation: $data['operation'],
            cacheKey: $data['cache_key'],
            stateKey: $data['state_key'],
            ttl: $data['ttl'] ?? null,
        );
    }
}
