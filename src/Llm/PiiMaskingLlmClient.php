<?php

namespace Phpkaiharness\Llm;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;

class PiiMaskingLlmClient implements LlmClientInterface
{
    /**
     * Map of placeholder token => original value, populated during masking.
     *
     * @var array<string, string>
     */
    private array $maskMap = [];

    /**
     * @param  LlmClientInterface  $innerClient  The wrapped LLM client to delegate to.
     * @param  array<string, string>  $patterns  Map of pattern name => regex.
     *                                           Keys are used to generate readable placeholder tokens (e.g. [EMAIL_1]).
     */
    public function __construct(
        protected LlmClientInterface $innerClient,
        protected array $patterns = [
            'EMAIL' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            'IP' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            'CREDIT_CARD' => '/\b(?:\d[ \-]*?){13,16}\b/',
            'API_KEY' => '/\b[A-Za-z0-9_\-]{32,64}\b/',
        ]
    ) {}

    /**
     * Mask PII in all outbound message content, delegate to inner client, then restore PII in the response.
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
        $enabled = (function_exists('config') && function_exists('app') && app()->bound('config')) ? (bool) config('harness.pii_masking.enabled', true) : true;
        if (! $enabled) {
            return $this->innerClient->chat($systemPrompt, $messages, $tools, $model, $sessionId, $collector, $onChunk);
        }

        $this->maskMap = [];

        $maskedSystemPrompt = $this->maskText($systemPrompt);
        $maskedMessages = $this->maskMessages($messages);

        if ($collector && $sessionId) {
            $collector->recordEvent(
                $sessionId,
                'pii_masking',
                'PiiMaskingLlmClient',
                ['redacted' => array_keys($this->maskMap), 'count' => count($this->maskMap)],
                json_encode(['status' => empty($this->maskMap) ? 'No PII patterns matched' : 'PII masked successfully', 'mask_map' => $this->maskMap], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
            );
        }

        $response = $this->innerClient->chat(
            $maskedSystemPrompt,
            $maskedMessages,
            $tools,
            $model,
            $sessionId,
            $collector,
            $onChunk !== null ? function (string $chunk) use ($onChunk): void {
                $onChunk($this->restoreText($chunk));
            } : null
        );

        return [
            'content' => $response['content'] !== null ? $this->restoreText($response['content']) : null,
            'tool_calls' => $response['tool_calls'] ?? [],
        ];
    }

    /**
     * Replace all PII occurrences in a string with stable placeholder tokens.
     */
    protected function maskText(string $text): string
    {
        foreach ($this->patterns as $type => $pattern) {
            $counter = 1;
            $text = preg_replace_callback(
                $pattern,
                function (array $matches) use ($type, &$counter): string {
                    $token = '['.strtoupper($type).'_'.$counter.']';
                    $this->maskMap[$token] = $matches[0];
                    $counter++;

                    return $token;
                },
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Mask PII in the content field of every message in the history array.
     *
     * @param  array<array{role: string, content: string|null}>  $messages
     * @return array<array{role: string, content: string|null}>
     */
    protected function maskMessages(array $messages): array
    {
        return array_map(function (array $msg): array {
            if (isset($msg['content']) && is_string($msg['content'])) {
                $msg['content'] = $this->maskText($msg['content']);
            }

            return $msg;
        }, $messages);
    }

    /**
     * Restore all placeholder tokens back to their original PII values.
     */
    protected function restoreText(string $text): string
    {
        return str_replace(
            array_keys($this->maskMap),
            array_values($this->maskMap),
            $text
        );
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
