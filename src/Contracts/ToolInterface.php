<?php

namespace Phpkaiharness\Contracts;

interface ToolInterface
{
    /**
     * Get the name of the tool (alphanumeric and underscores only).
     */
    public function name(): string;

    /**
     * Get the tool's description for the LLM.
     */
    public function description(): string;

    /**
     * Get the parameters JSON-schema definition.
     * Must return an array matching OpenAPI/JSON Schema parameters property.
     */
    public function schema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  array  $args  Tool arguments passed by the LLM.
     * @return string Returns string response (typically JSON-encoded object or raw status).
     */
    public function execute(array $args): string;
}
