<?php

namespace Cainy\Laragraph\Engine;

use Closure;
use Random\Randomizer;
use Throwable;

class RetryPolicy
{
    public float $initialInterval;
    public float $backoffFactor;
    public float $maxInterval;
    public int $maxAttempts;
    public bool $jitter;

    public function __construct(
        ?float $initialInterval = null,
        ?float $backoffFactor = null,
        ?float $maxInterval = null,
        ?int $maxAttempts = null,
        ?bool $jitter = null,
        /** @var array<class-string<Throwable>>|Closure(Throwable): bool|null */
        public array|Closure|null $retryOn = null,
    ) {
        $this->initialInterval = $initialInterval ?? config('laragraph.retry.initial_interval', 0.5);
        $this->backoffFactor   = $backoffFactor ?? config('laragraph.retry.backoff_factor', 2.0);
        $this->maxInterval     = $maxInterval ?? config('laragraph.retry.max_interval', 128.0);
        $this->maxAttempts     = $maxAttempts ?? config('laragraph.max_node_attempts', 3);
        $this->jitter          = $jitter ?? config('laragraph.retry.jitter', true);
    }

    /**
     * Calculates the array of wait times (in seconds) for Laravel's queue worker.
     * Laravel expects an array of integers for the backoff intervals.
     * * @return array<int>
     */
    public function calculateBackoff(): array
    {
        $intervals = [];
        $currentInterval = $this->initialInterval;

        // We calculate maxAttempts - 1 because the first attempt has no backoff
        for ($i = 0; $i < $this->maxAttempts - 1; $i++) {
            $interval = min($currentInterval, $this->maxInterval);

            if ($this->jitter) {
                // We want up to 25% jitter in either direction
                $jitterAmount = $interval * 0.25;

                $randomizer = new Randomizer();
                $randomJitter = $randomizer->getFloat(-$jitterAmount, $jitterAmount);

                $interval += $randomJitter;
            }

            // We round up so a 0.5s initial interval becomes 1s.
            $intervals[] = (int) ceil($interval);

            $currentInterval *= $this->backoffFactor;
        }

        return $intervals;
    }

    /**
     * Determines if a specific exception should trigger a retry.
     */
    public function shouldRetry(Throwable $e): bool
    {
        if ($this->retryOn === null) {
            return true;
        }

        if ($this->retryOn instanceof Closure) {
            return ($this->retryOn)($e);
        }

        return array_any($this->retryOn, fn($exceptionClass) => $e instanceof $exceptionClass);
    }
}
