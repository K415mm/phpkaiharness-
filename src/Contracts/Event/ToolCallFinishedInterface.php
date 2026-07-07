<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched immediately after a tool execution completes.
 * Useful for result logging, performance monitoring, and audit trails.
 */
interface ToolCallFinishedInterface extends AgentEventInterface
{
    /**
     * Get the unique tool call ID assigned by the LLM.
     */
    public function getCallId(): string;

    /**
     * Get the registered name of the tool that was executed.
     */
    public function getToolName(): string;

    /**
     * Get the arguments that were passed to the tool.
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array;

    /**
     * Get the string result returned by the tool execution.
     */
    public function getResult(): string;

    /**
     * Get the execution duration of the tool in milliseconds.
     */
    public function getDurationMs(): int;
}
