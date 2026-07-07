<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\LlmCallStartedInterface;

final class LlmCallStarted implements LlmCallStartedInterface
{
    /**
     * @param  array<array{role: string, content: string|null}>  $messages
     * @param  array<mixed>  $tools
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly int $iteration,
        private readonly string $model,
        private readonly array $messages,
        private readonly array $tools
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

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTools(): array
    {
        return $this->tools;
    }
}
