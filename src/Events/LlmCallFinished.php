<?php

namespace Phpkaiharness\Events;

use Phpkaiharness\Contracts\Event\LlmCallFinishedInterface;

final class LlmCallFinished implements LlmCallFinishedInterface
{
    /**
     * @param  array<array{id: string, name: string, arguments: array<mixed>}>  $toolCalls
     * @param  array{prompt_tokens: int, completion_tokens: int}  $usage
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $agentName,
        private readonly int $iteration,
        private readonly string $model,
        private readonly ?string $responseContent,
        private readonly array $toolCalls,
        private readonly int $durationMs,
        private readonly array $usage
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

    public function getResponseContent(): ?string
    {
        return $this->responseContent;
    }

    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }
}
