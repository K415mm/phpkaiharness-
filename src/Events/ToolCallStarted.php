<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\ToolCallStartedInterface;

final class ToolCallStarted implements ToolCallStartedInterface
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly string $callId,
        private readonly string $toolName,
        private readonly array $arguments
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
}
