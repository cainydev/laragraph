<?php

namespace Cainy\Laragraph\Contracts;

/**
 * Allows a Node to define a custom, human-readable name for the workflow graph.
 *
 * If not implemented, the workflow registry will default to using the node's
 * Fully Qualified Class Name (FQCN). Implementing this is highly recommended
 * for visually clean React Flow graphs and simplified edge routing.
 */
interface HasName
{
    /**
     * Determine the internal identifier for this node.
     *
     * @return string e.g., 'research_agent' or 'billing_api'
     */
    public function name(): string;
}
