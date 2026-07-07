<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched immediately before the AgentLoop executes a tool call
 * that was requested by the LLM. Useful for pre-execution validation
 * or audit logging.
 */
interface ToolCallStartedInterface extends AgentEventInterface
{
    /**
     * Get the unique tool call ID assigned by the LLM.
     */
    public function getCallId(): string;

    /**
     * Get the registered name of the tool being invoked.
     */
    public function getToolName(): string;

    /**
     * Get the arguments the LLM passed to the tool.
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array;
}
