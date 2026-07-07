<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\AgentFinishedInterface;

final class AgentFinished implements AgentFinishedInterface
{
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly string $finalResponse,
        private readonly int $durationMs,
        private readonly int $iterations
    ) {}

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getFinalResponse(): string
    {
        return $this->finalResponse;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }
}
