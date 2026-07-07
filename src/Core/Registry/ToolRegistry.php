<?php

namespace Phpkaiharness\Core\Registry;

use InvalidArgumentException;
use Phpkaiharness\Contracts\ToolInterface;

class ToolRegistry
{
    /**
     * Internal store for registered tools.
     *
     * @var array<string, ToolInterface>
     */
    protected array $tools = [];

    /**
     * Register/Attach a tool to the registry.
     */
    public function attach(ToolInterface $tool): self
    {
        $name = $tool->name();
        if (empty($name)) {
            throw new InvalidArgumentException('Tool name cannot be empty.');
        }
        $this->tools[$name] = $tool;

        return $this;
    }

    /**
     * Detach/Remove a tool by name.
     */
    public function detach(string $name): self
    {
        unset($this->tools[$name]);

        return $this;
    }

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get a registered tool by name.
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get all registered tool instances.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Convert registered tool schemas to the standard LLM compatible schema array format.
     */
    public function serializeSchemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ];
        }

        return $schemas;
    }
}
