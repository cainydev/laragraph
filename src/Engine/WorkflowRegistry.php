<?php

namespace Cainy\Laragraph\Engine;

use Cainy\Laragraph\Builder\CompiledWorkflow;
use Cainy\Laragraph\Builder\Workflow;

class WorkflowRegistry
{
    /** @var array<string, string|callable> */
    private array $definitions = [];

    /** @var array<string, CompiledWorkflow> */
    private array $compiled = [];

    /**
     * @param  array<string, string|callable>  $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $name => $definition) {
            $this->register($name, $definition);
        }
    }

    public function register(string $name, string|callable $definition): void
    {
        $this->definitions[$name] = $definition;
        unset($this->compiled[$name]);
    }

    public function resolve(string $name): CompiledWorkflow
    {
        if (isset($this->compiled[$name])) {
            return $this->compiled[$name];
        }

        $definition = $this->definitions[$name]
            ?? throw new \InvalidArgumentException("Workflow [{$name}] is not registered.");

        if (is_callable($definition)) {
            $workflow = $definition();
        } else {
            $workflow = app($definition);
        }

        if ($workflow instanceof CompiledWorkflow) {
            return $this->compiled[$name] = $workflow;
        }

        if ($workflow instanceof Workflow) {
            return $this->compiled[$name] = $workflow->compile();
        }

        throw new \InvalidArgumentException("Workflow [{$name}] must resolve to a Workflow or CompiledWorkflow instance.");
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * @return array<string, string|callable>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
