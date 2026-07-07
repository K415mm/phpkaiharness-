<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\AgentStartedInterface;

final class AgentStarted implements AgentStartedInterface
{
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly string $prompt,
        private readonly string $systemPrompt,
        private readonly string $model
    ) {}

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
