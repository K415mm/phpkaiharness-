<?php

namespace Phpkaiharness\Contracts;

interface MemoryInterface
{
    /**
     * Retrieve the full conversation history for a given session.
     *
     * @return array<array{role: string, content: string, tool_calls?: array<mixed>, tool_call_id?: string, name?: string}>
     */
    public function getHistory(string $sessionId): array;

    /**
     * Append a single message to the conversation history.
     *
     * @param  array{role: string, content: string, tool_calls?: array<mixed>, tool_call_id?: string, name?: string}  $message
     */
    public function appendMessage(string $sessionId, array $message): void;

    /**
     * Prune old history turns to fit context limits, keeping only the most recent $keepTurns turns.
     *
     * @param  int  $keepTurns  Number of recent turns to keep.
     */
    public function pruneHistory(string $sessionId, int $keepTurns): void;

    /**
     * Clear all conversation history for a given session.
     */
    public function clear(string $sessionId): void;
}
