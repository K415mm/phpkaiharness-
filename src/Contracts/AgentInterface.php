<?php

namespace Phpkaiharness\Contracts;

interface AgentInterface
{
    /**
     * Get the system instructions for the agent.
     */
    public function getInstructions(): string;

    /**
     * Get the model identifier (e.g. 'hermes-3-llama-3-8b').
     */
    public function getModel(): string;

    /**
     * Get the provider name (e.g. 'ollama' or 'openrouter').
     */
    public function getProvider(): string;

    /**
     * Get the list of tools associated with the agent.
     *
     * @return ToolInterface[]
     */
    public function getTools(): array;
}
