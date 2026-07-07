<?php

namespace Phpkaiharness\Llm;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RateLimitedLlmClient implements LlmClientInterface
{
    protected LoggerInterface $logger;

    /**
     * Sliding window of request timestamps (microtime float values).
     *
     * @var array<float>
     */
    protected array $requestTimestamps = [];

    /**
     * @param  LlmClientInterface  $innerClient  The wrapped LLM client to delegate to.
     * @param  int  $requestsPerMinute  Maximum number of requests allowed per 60-second sliding window.
     * @param  int  $cooldownMs  Minimum milliseconds to wait between consecutive requests.
     */
    public function __construct(
        protected LlmClientInterface $innerClient,
        protected int $requestsPerMinute = 60,
        protected int $cooldownMs = 0,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Enforce rate limits before delegating the call to the inner client.
     *
     * @return array{content: ?string, tool_calls: array<mixed>}
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        string $model = '',
        ?string $sessionId = null,
        ?AnalyticsCollectorInterface $collector = null,
        ?callable $onChunk = null
    ): array {
        $enabled = (function_exists('config') && function_exists('app') && app()->bound('config')) ? (bool) config('harness.rate_limiting.enabled', true) : true;
        if (! $enabled) {
            return $this->innerClient->chat($systemPrompt, $messages, $tools, $model, $sessionId, $collector, $onChunk);
        }

        $this->enforceRateLimit($sessionId, $collector);

        return $this->innerClient->chat(
            $systemPrompt,
            $messages,
            $tools,
            $model,
            $sessionId,
            $collector,
            $onChunk
        );
    }

    /**
     * Prune timestamps older than 60 seconds, sleep if the sliding window is full,
     * then optionally apply a per-request cooldown gap.
     */
    protected function enforceRateLimit(?string $sessionId = null, ?AnalyticsCollectorInterface $collector = null): void
    {
        $now = microtime(true);
        $windowStart = $now - 60.0;

        $this->requestTimestamps = array_values(
            array_filter($this->requestTimestamps, static fn (float $ts): bool => $ts >= $windowStart)
        );

        $throttled = false;

        if (count($this->requestTimestamps) >= $this->requestsPerMinute) {
            $oldestInWindow = $this->requestTimestamps[0];
            $waitSeconds = 60.0 - ($now - $oldestInWindow);

            if ($waitSeconds > 0) {
                $waitUs = (int) ($waitSeconds * 1_000_000);
                $this->logger->info('RateLimitedLlmClient: window full, sleeping '.round($waitSeconds, 2).'s before next request.');
                if ($collector && $sessionId) {
                    $collector->recordEvent(
                        $sessionId,
                        'rate_limit',
                        'RateLimitedLlmClient',
                        ['wait_seconds' => $waitSeconds],
                        'Rate limit window full: delayed request to prevent HTTP 429.',
                        (int) ($waitSeconds * 1000)
                    );
                }
                $throttled = true;
                usleep($waitUs);
            }
        }

        if (! $throttled && $this->cooldownMs > 0 && ! empty($this->requestTimestamps)) {
            $lastTs = end($this->requestTimestamps);
            $elapsedMs = (int) ((microtime(true) - $lastTs) * 1000);
            $remaining = $this->cooldownMs - $elapsedMs;

            if ($remaining > 0) {
                $this->logger->debug('RateLimitedLlmClient: cooldown gap, sleeping '.$remaining.'ms.');
                if ($collector && $sessionId) {
                    $collector->recordEvent(
                        $sessionId,
                        'rate_limit',
                        'RateLimitedLlmClient',
                        ['cooldown_ms' => $this->cooldownMs, 'remaining_ms' => $remaining],
                        'Rate limit cooldown gap applied.',
                        $remaining
                    );
                }
                $throttled = true;
                usleep($remaining * 1000);
            }
        }

        if (! $throttled && $collector && $sessionId) {
            $collector->recordEvent(
                $sessionId,
                'rate_limit',
                'RateLimitedLlmClient',
                [
                    'requests_per_minute' => $this->requestsPerMinute,
                    'cooldown_ms' => $this->cooldownMs,
                    'current_window_count' => count($this->requestTimestamps),
                ],
                'Allowed: Request within rate limits.'
            );
        }

        $this->requestTimestamps[] = microtime(true);
    }

    /**
     * Delegates model resolution to the inner client.
     * AgentLoop uses this to auto-detect Qwen/Gemma for prompt optimization.
     */
    public function getResolvedModel(): string
    {
        return $this->innerClient->getResolvedModel();
    }
}
