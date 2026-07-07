<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched when an AgentLoop::run() execution completes,
 * whether by producing a final response, hitting iteration limits, or encountering an error.
 */
interface AgentFinishedInterface extends AgentEventInterface
{
    /**
     * Get the final text response returned by the agent.
     */
    public function getFinalResponse(): string;

    /**
     * Get the total wall-clock duration of the agent run in milliseconds.
     */
    public function getDurationMs(): int;

    /**
     * Get the number of loop iterations that were executed.
     */
    public function getIterations(): int;
}
