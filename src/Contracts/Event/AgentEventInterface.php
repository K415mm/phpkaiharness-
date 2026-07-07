<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Base interface for all phpkaiharness agent loop events.
 */
interface AgentEventInterface
{
    /**
     * Get the session identifier the event belongs to.
     */
    public function getSessionId(): string;

    /**
     * Get the human-readable agent name that emitted the event.
     */
    public function getAgentName(): string;
}
