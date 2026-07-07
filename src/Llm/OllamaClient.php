<?php

namespace Phpkaiharness\Llm;

use Exception;
use GuzzleHttp\Client;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;

class OllamaClient implements LlmClientInterface
{
    protected Client $httpClient;

    protected string $baseUrl;

    protected string $defaultModel;

    public function __construct(string $baseUrl = 'http://localhost:11434', string $defaultModel = 'hermes-3-llama-3-8b')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultModel = $defaultModel;
        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 120.0,
        ]);
    }

    /**
     * Return the effective model this client sends to Ollama.
     * AgentLoop uses this to auto-detect Qwen/Gemma for prompt optimization.
     */
    public function getResolvedModel(): string
    {
        return $this->defaultModel;
    }

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

        // Compile messages array including system prompt at index 0 if not empty
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

        // Attach tools if supported and available
        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $startTime = microtime(true);

        try {
            if ($onChunk !== null) {
                return $this->chatStreaming($payload, $startTime, $resolvedModel, $sessionId, $collector, $onChunk);
            }

            $response = $this->httpClient->post('/api/chat', [
                'json' => $payload,
            ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = json_decode($response->getBody()->getContents(), true);
            if (! isset($body['message'])) {
                throw new Exception('Malformed response from Ollama API: '.json_encode($body));
            }

            $message = $body['message'];
            $content = $message['content'] ?? '';
            $rawToolCalls = $message['tool_calls'] ?? [];

            // Standardize tool calls schema
            $formattedToolCalls = [];
            foreach ($rawToolCalls as $index => $tc) {
                // Ollama function format can be under 'function' key
                $function = $tc['function'] ?? [];
                $toolName = $function['name'] ?? '';
                $toolArgs = $function['arguments'] ?? [];

                $formattedToolCalls[] = [
                    'id' => $tc['id'] ?? 'call_'.uniqid().'_'.$index,
                    'name' => $toolName,
                    'arguments' => $toolArgs,
                ];
            }

            // Extract token usage
            $usage = [
                'prompt_tokens' => $body['prompt_eval_count'] ?? 0,
                'completion_tokens' => $body['eval_count'] ?? 0,
            ];

            if ($collector && $sessionId) {
                $collector->recordLlmCall($sessionId, $resolvedModel, $payload, $body, $durationMs, $usage);
            }

            return [
                'content' => $content,
                'tool_calls' => $formattedToolCalls,
            ];

        } catch (Exception $e) {
            throw new Exception('Ollama connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Handle a streaming response from Ollama, invoking $onChunk for each NDJSON delta.
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
        $response = $this->httpClient->post('/api/chat', [
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
            if ($line === '') {
                continue;
            }

            $parsed = json_decode($line, true);
            $delta = $parsed['message']['content'] ?? '';
            if ($delta !== '') {
                $fullContent .= $delta;
                $onChunk($delta);
            }

            if (($parsed['done'] ?? false) === true) {
                break;
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
