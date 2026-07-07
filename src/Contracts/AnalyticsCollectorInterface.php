<?php

namespace Phpkaiharness\Contracts;

interface AnalyticsCollectorInterface
{
    /**
     * Initialize a new execution session tracking.
     *
     * @param  string  $sessionId  Unique identifier for this agent run.
     * @param  string  $prompt  The user input prompt.
     * @param  string  $method  The routing method used (e.g. 'fast-path', 'router-classified', 'executor-loop').
     */
    public function startSession(
        string $sessionId,
        string $prompt,
        string $method,
        ?string $parentSessionId = null,
        int $interactionIndex = 0,
        ?string $rootSessionId = null,
        ?string $requestId = null,
        string $sessionType = 'interaction'
    ): void;

    /**
     * Terminate the session tracking with final stats.
     *
     * @param  string  $sessionId  The session identifier.
     * @param  string  $response  The final text reply returned to the user.
     * @param  int  $totalDurationMs  Total elapsed time in milliseconds.
     * @param  int  $iterations  Total count of loop iterations run.
     */
    public function endSession(string $sessionId, string $response, int $totalDurationMs, int $iterations): void;

    /**
     * Record a specific LLM request-response invocation.
     *
     * @param  string  $sessionId  The session identifier.
     * @param  string  $model  The model name queried (e.g., gemma4:12b-it-qat).
     * @param  array  $payload  The raw JSON-serializable request payload sent.
     * @param  array  $response  The parsed JSON-serializable response payload received.
     * @param  int  $durationMs  Round-trip HTTP request duration in milliseconds.
     * @param  array  $usage  Token usage statistics containing 'prompt_tokens', 'completion_tokens', and optionally 'cost'.
     */
    public function recordLlmCall(
        string $sessionId,
        string $model,
        array $payload,
        array $response,
        int $durationMs,
        array $usage
    ): void;

    /**
     * Record a specific tool execution details.
     *
     * @param  string  $sessionId  The session identifier.
     * @param  string  $toolName  The unique identifier of the tool.
     * @param  array  $arguments  The parameters passed to the tool.
     * @param  string  $result  The returned string value of the tool.
     * @param  int  $durationMs  Execution duration in milliseconds.
     */
    public function recordToolCall(
        string $sessionId,
        string $toolName,
        array $arguments,
        string $result,
        int $durationMs
    ): void;

    /**
     * Record a specific enhancement event (e.g. cache hit, PII masking, rate limit delay, guardrail check).
     */
    public function recordEvent(
        string $sessionId,
        string $type,
        string $name,
        array $payload,
        string $response,
        int $durationMs = 0
    ): void;

    /**
     * Record a persistent fact/relationship to the cognitive graph memory layer.
     */
    public function recordFact(string $sessionId, string $fact): void;
}
