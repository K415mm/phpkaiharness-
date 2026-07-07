<?php

namespace Phpkaiharness\Contracts\Event;

/**
 * Dispatched immediately before the AgentLoop sends a request to the LLM client.
 * Useful for logging, token-budget pre-checks, or rate-limit enforcement.
 */
interface LlmCallStartedInterface extends AgentEventInterface
{
    /**
     * Get the current loop iteration number (0-indexed).
     */
    public function getIteration(): int;

    /**
     * Get the model identifier being called.
     */
    public function getModel(): string;

    /**
     * Get the full message history payload being sent to the LLM.
     *
     * @return array<array{role: string, content: string|null}>
     */
    public function getMessages(): array;

    /**
     * Get the serialized tool schemas included in the request.
     *
     * @return array<mixed>
     */
    public function getTools(): array;
}
