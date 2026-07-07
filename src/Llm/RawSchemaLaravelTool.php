<?php

namespace Phpkaiharness\Llm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as LaravelTool;
use Laravel\Ai\Tools\Request as LaravelRequest;
use Stringable;

class RawSchemaLaravelTool implements LaravelTool
{
    protected array $schemaData;

    public function __construct(array $schemaData)
    {
        $this->schemaData = $schemaData;
    }

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return $this->schemaData['function']['name'] ?? $this->schemaData['name'] ?? '';
    }

    /**
     * Get the tool description.
     */
    public function description(): Stringable|string
    {
        return $this->schemaData['function']['description'] ?? $this->schemaData['description'] ?? '';
    }

    /**
     * Dummy handler because execution is handled by Phpkaiharness AgentLoop.
     */
    public function handle(LaravelRequest $request): Stringable|string
    {
        return '';
    }

    /**
     * Map the pre-compiled JSON schema properties directly to Laravel Types.
     */
    public function schema(JsonSchema $schema): array
    {
        $props = $this->schemaData['function']['parameters']['properties'] ?? $this->schemaData['parameters']['properties'] ?? [];
        $required = $this->schemaData['function']['parameters']['required'] ?? $this->schemaData['parameters']['required'] ?? [];

        $mapped = [];
        foreach ($props as $name => $prop) {
            $type = $prop['type'] ?? 'string';
            $desc = $prop['description'] ?? '';

            $typeObj = match ($type) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array(),
                'object' => $schema->object([]),
                default => $schema->string(),
            };

            if (! empty($desc)) {
                $typeObj = $typeObj->description($desc);
            }

            if (in_array($name, $required)) {
                $typeObj = $typeObj->required();
            }

            $mapped[$name] = $typeObj;
        }

        return $mapped;
    }
}
