<?php

namespace Cainy\Laragraph\Contracts;

/**
 * Allows a Node to declare a maximum execution time limit.
 *
 * If the node's logic (such as a long-running HTTP request to an AI provider)
 * exceeds this limit, the Laravel queue worker will aggressively terminate the
 * process, throw a timeout exception, and potentially trigger the RetryPolicy.
 */
interface HasTimeout
{
    /**
     * Determine the maximum number of seconds this node is allowed to run.
     *
     * @return int The timeout in seconds.
     */
    public function timeout(): int;
}
