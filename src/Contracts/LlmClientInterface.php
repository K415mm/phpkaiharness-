<?php

namespace Phpkaiharness\Contracts;

interface LlmClientInterface
{
    /**
     * Send messages to the LLM backend.
     *
     * @param  string  $systemPrompt  System prompt with instructions.
     * @param  array  $messages  Conversation history.
     * @param  array  $tools  Array of serialized tool schemas.
     * @param  string  $model  The model identifier to use.
     * @param  string|null  $sessionId  Session ID for analytics tracking.
     * @param  AnalyticsCollectorInterface|null  $collector  Optional analytics collector.
     * @param  callable|null  $onChunk  Optional streaming callback: function(string $chunk): void
     * @return array Returns structure with:
     *               - 'content': ?string (Final message)
     *               - 'tool_calls': ?array (List of tool calls containing 'id', 'name', 'arguments')
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        string $model = '',
        ?string $sessionId = null,
        ?AnalyticsCollectorInterface $collector = null,
        ?callable $onChunk = null
    ): array;

    /**
     * Return the effective model this client will send to its provider.
     * Used by AgentLoop for model-aware prompt optimization.
     */
    public function getResolvedModel(): string;
}
