<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\LlmStreamChunkReceivedInterface;

final class LlmStreamChunkReceived implements LlmStreamChunkReceivedInterface
{
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly int $iteration,
        private readonly string $chunk
    ) {}

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function getChunk(): string
    {
        return $this->chunk;
    }
}
