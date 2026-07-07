<?php

namespace Phpkaiharness\Core\Prompt;

use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Mutable value object carrying prompt data through the processing pipeline.
 *
 * Each PromptProcessorInterface stage reads from and writes to this context,
 * allowing the stages to chain without knowing about each other.
 */
class PromptContext
{
    public string $effectiveUserPrompt;

    public string $optimizedSystemPrompt;

    public ?AgentPrompt $laravelPrompt = null;

    public function __construct(
        public readonly string $userPrompt,
        public readonly string $systemPrompt,
        public string $effectiveModel,
        public readonly string $sessionId,
        public readonly ?AnalyticsCollectorInterface $collector,
        public readonly LlmClientInterface $llmClient,
        public readonly string $agentName,
        public readonly ?LoggerInterface $logger = null
    ) {
        $this->effectiveUserPrompt = $userPrompt;
        $this->optimizedSystemPrompt = $systemPrompt;
    }
}
