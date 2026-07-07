<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched immediately after the LLM client returns a response.
 * Useful for token-usage tracking, cost accounting, and response logging.
 */
interface LlmCallFinishedInterface extends AgentEventInterface
{
    /**
     * Get the current loop iteration number (0-indexed).
     */
    public function getIteration(): int;

    /**
     * Get the model identifier that was called.
     */
    public function getModel(): string;

    /**
     * Get the text content returned by the LLM (may be null if tool calls were returned).
     */
    public function getResponseContent(): ?string;

    /**
     * Get the tool calls returned by the LLM (empty array if a final text response was returned).
     *
     * @return array<array{id: string, name: string, arguments: array<mixed>}>
     */
    public function getToolCalls(): array;

    /**
     * Get the round-trip duration of the LLM HTTP call in milliseconds.
     */
    public function getDurationMs(): int;

    /**
     * Get token usage statistics for this call.
     *
     * @return array{prompt_tokens: int, completion_tokens: int}
     */
    public function getUsage(): array;
}
