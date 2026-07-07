<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\ToolCallFinishedInterface;

final class ToolCallFinished implements ToolCallFinishedInterface
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly string $callId,
        private readonly string $toolName,
        private readonly array $arguments,
        private readonly string $result,
        private readonly int $durationMs
    ) {}

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getCallId(): string
    {
        return $this->callId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }
}
