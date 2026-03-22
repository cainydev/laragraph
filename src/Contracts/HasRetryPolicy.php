<?php

namespace Cainy\Laragraph\Contracts;

use Cainy\Laragraph\Engine\RetryPolicy;

/**
 * Allows a Node to encapsulate its own error recovery and exponential backoff logic.
 *
 * When a Node throws an exception, the Laragraph engine will consult this policy
 * to determine if the node should be retried, how long to wait, and whether to
 * add random jitter to prevent thundering herd API requests.
 */
interface HasRetryPolicy
{
    /**
     * Define the retry policy for this specific node.
     *
     * @return RetryPolicy The configuration object dictating max attempts and backoff intervals.
     */
    public function retryPolicy(): RetryPolicy;
}
