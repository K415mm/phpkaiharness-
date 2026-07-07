<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched for each incremental text chunk received during a streaming LLM response.
 * Allows PSR-14 event listeners to relay real-time token output to UIs, websockets, or log streams.
 */
interface LlmStreamChunkReceivedInterface extends AgentEventInterface
{
    /**
     * Get the current loop iteration number (0-indexed).
     */
    public function getIteration(): int;

    /**
     * Get the raw text token chunk received from the streaming response.
     */
    public function getChunk(): string;
}
