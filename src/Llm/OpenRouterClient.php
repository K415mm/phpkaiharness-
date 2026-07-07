<?php

namespace Phpkaiharness\Llm;

use Exception;
use GuzzleHttp\Client;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;

class OpenRouterClient implements LlmClientInterface
{
    protected Client $httpClient;

    protected string $apiKey;

    protected string $defaultModel;

    public function __construct(string $apiKey = '', string $defaultModel = 'meta-llama/llama-3-8b-instruct')
    {
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel;
        $this->httpClient = new Client([
            'base_uri' => 'https://openrouter.ai',
            'timeout' => 120.0,
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
                // Optional headers for OpenRouter rankings/analytics
                'HTTP-Referer' => 'https://github.com/HKUDS/OpenHarness',
                'X-Title' => 'phpkaiharness',
            ],
        ]);
    }

    /**
     * Return the effective model this client sends to OpenRouter.
     * AgentLoop uses this to auto-detect Qwen/Gemma for prompt optimization.
     */
    public function getResolvedModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Send chat request to OpenRouter `/api/v1/chat/completions` endpoint.
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
        $resolvedModel = empty($model) ? $this->defaultModel : $model;

        $compiledMessages = [];
        if (! empty($systemPrompt)) {
            $compiledMessages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        foreach ($messages as $msg) {
            $compiledMsg = [
                'role' => $msg['role'],
                'content' => $msg['content'] ?? '',
            ];
            if (isset($msg['tool_calls']) && $msg['tool_calls'] !== null) {
                $formattedToolCalls = [];
                foreach ($msg['tool_calls'] as $tc) {
                    $args = $tc['arguments'] ?? [];
                    if (is_array($args)) {
                        $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                    }
                    $formattedToolCalls[] = [
                        'id' => $tc['id'] ?? uniqid('call_'),
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'] ?? '',
                            'arguments' => $args,
                        ],
                    ];
                }
                $compiledMsg['tool_calls'] = $formattedToolCalls;
            }
            if (isset($msg['tool_call_id'])) {
                $compiledMsg['tool_call_id'] = $msg['tool_call_id'];
            }
            if (isset($msg['name'])) {
                $compiledMsg['name'] = $msg['name'];
            }
            $compiledMessages[] = $compiledMsg;
        }

        $payload = [
            'model' => $resolvedModel,
            'messages' => $compiledMessages,
            'stream' => $onChunk !== null,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);

        try {
            if ($onChunk !== null) {
                return $this->chatStreaming($payload, $startTime, $resolvedModel, $sessionId, $collector, $onChunk);
            }

            $response = $this->httpClient->post('/api/v1/chat/completions', [
                'json' => $payload,
            ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = json_decode($response->getBody()->getContents(), true);
            if (! isset($body['choices'][0]['message'])) {
                throw new Exception('Malformed response from OpenRouter API: '.json_encode($body));
            }

            $choiceMessage = $body['choices'][0]['message'];
            $content = $choiceMessage['content'] ?? '';
            $rawToolCalls = $choiceMessage['tool_calls'] ?? [];

            // OpenRouter/OpenAI tool call standard mapping
            $formattedToolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $function = $tc['function'] ?? [];
                $toolName = $function['name'] ?? '';
                $toolArgsRaw = $function['arguments'] ?? '';

                $arguments = [];
                if (! empty($toolArgsRaw)) {
                    if (is_string($toolArgsRaw)) {
                        $arguments = json_decode($toolArgsRaw, true) ?? [];
                    } else {
                        $arguments = $toolArgsRaw;
                    }
                }

                $formattedToolCalls[] = [
                    'id' => $tc['id'] ?? uniqid('call_'),
                    'name' => $toolName,
                    'arguments' => $arguments,
                ];
            }

            // Extract token usage
            $usage = [
                'prompt_tokens' => $body['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $body['usage']['completion_tokens'] ?? 0,
            ];

            if ($collector && $sessionId) {
                $collector->recordLlmCall($sessionId, $resolvedModel, $payload, $body, $durationMs, $usage);
            }

            return [
                'content' => $content,
                'tool_calls' => $formattedToolCalls,
            ];

        } catch (Exception $e) {
            throw new Exception('OpenRouter connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle a streaming response from OpenRouter (SSE), invoking $onChunk for each delta.
     *
     * @param  array<string, mixed>  $payload
     * @return array{content: string, tool_calls: array<mixed>}
     */
    protected function chatStreaming(
        array $payload,
        float $startTime,
        string $resolvedModel,
        ?string $sessionId,
        ?AnalyticsCollectorInterface $collector,
        callable $onChunk
    ): array {
        $response = $this->httpClient->post('/api/v1/chat/completions', [
            'json' => $payload,
            'stream' => true,
        ]);

        $fullContent = '';
        $body = $response->getBody();

        while (! $body->eof()) {
            $line = '';
            $char = '';
            while (! $body->eof() && ($char = $body->read(1)) !== "\n") {
                $line .= $char;
            }

            $line = trim($line);
            if ($line === '' || $line === 'data: [DONE]') {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                $parsed = json_decode($json, true);
                $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $fullContent .= $delta;
                    $onChunk($delta);
                }
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($collector && $sessionId) {
            $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
            $collector->recordLlmCall($sessionId, $resolvedModel, $payload, ['content' => $fullContent], $durationMs, $usage);
        }

        return ['content' => $fullContent, 'tool_calls' => []];
    }
}
