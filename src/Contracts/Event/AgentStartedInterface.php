<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched at the very beginning of an AgentLoop::run() execution,
 * before any LLM calls or tool executions take place.
 */
interface AgentStartedInterface extends AgentEventInterface
{
    /**
     * Get the initial user prompt that triggered the agent run.
     */
    public function getPrompt(): string;

    /**
     * Get the system instructions used for this agent run.
     */
    public function getSystemPrompt(): string;

    /**
     * Get the model identifier resolved for this execution.
     */
    public function getModel(): string;
}
