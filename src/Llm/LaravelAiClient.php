<?php

namespace Phpkaiharness\Llm;

use App\Services\AiConfigHelper;
use Exception;
use GuzzleHttp\Client;
use Laravel\Ai\AiManager;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Mockery\MockInterface;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use PHPUnit\Framework\MockObject\MockObject;

class LaravelAiClient implements LlmClientInterface
{
    protected string $provider;

    protected string $model;

    /**
     * Providers that use `driver: openai` but only support the Chat Completions API,
     * NOT the OpenAI Responses API that Laravel AI SDK sends by default.
     */
    protected const CHAT_COMPLETIONS_ONLY_DRIVERS = ['lmstudio', 'openrouter', 'ollama', 'qwen', 'qwen_cloud'];

    public function __construct(string $provider = 'ollama', string $model = '')
    {
        $resolved = $this->resolveProviderAndModel($provider, $model);
        $this->provider = $resolved['provider'];
        $this->model = $resolved['model'];
    }

    /**
     * Resolve the provider and model based on package configurations and host application settings.
     *
     * @return array{provider: string, model: string}
     */
    protected function resolveProviderAndModel(string $passedProvider, string $passedModel): array
    {
        $provider = $passedProvider;
        $model = $passedModel;

        $isDefaultPassedProvider = ($passedProvider === 'ollama');
        $isDefaultPassedModel = empty($passedModel) || ($passedModel === 'llama3.2');

        $envProvider = env('PHPKAIHARNESS_PROVIDER');
        $envModel = env('PHPKAIHARNESS_MODEL');

        $configProvider = function_exists('config') && app()->bound('config') ? config('harness.default.provider') : null;
        $configModel = function_exists('config') && app()->bound('config') ? config('harness.default.model') : null;

        // Resolve Provider
        if (! $isDefaultPassedProvider) {
            $provider = $passedProvider;
        } elseif ($envProvider) {
            $provider = $envProvider;
        } elseif ($configProvider && $configProvider !== 'ollama') {
            $provider = $configProvider;
        } else {
            if (class_exists('App\Services\AiConfigHelper')) {
                try {
                    $hostConfig = AiConfigHelper::configure();
                    if (! empty($hostConfig['provider'])) {
                        $provider = is_object($hostConfig['provider']) && method_exists($hostConfig['provider'], 'value')
                            ? $hostConfig['provider']->value
                            : (string) $hostConfig['provider'];
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }

            if ($provider === 'ollama') {
                $hostDefault = function_exists('config') && app()->bound('config') ? config('ai.default') : null;
                if ($hostDefault) {
                    $provider = $hostDefault;
                }
            }
        }

        // Resolve Model
        if (! $isDefaultPassedModel) {
            $model = $passedModel;
        } elseif ($envModel) {
            $model = $envModel;
        } elseif ($configModel && $configModel !== 'llama3.2') {
            $model = $configModel;
        } else {
            $resolvedFromHelper = false;
            if (class_exists('App\Services\AiConfigHelper')) {
                try {
                    $hostConfig = AiConfigHelper::configure();
                    $hostProviderNormalized = '';
                    if (! empty($hostConfig['provider'])) {
                        $hostProviderNormalized = is_object($hostConfig['provider']) && method_exists($hostConfig['provider'], 'value')
                            ? $hostConfig['provider']->value
                            : (string) $hostConfig['provider'];
                    }
                    if ($hostProviderNormalized === $provider && ! empty($hostConfig['model'])) {
                        $model = $hostConfig['model'];
                        $resolvedFromHelper = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
            }

            if (! $resolvedFromHelper) {
                $model = match ($provider) {
                    'openai' => 'gpt-4o',
                    'anthropic' => 'claude-3-5-sonnet-latest',
                    'gemini' => 'gemini-1.5-flash',
                    'deepseek' => 'deepseek-chat',
                    'openrouter' => 'meta-llama/llama-3-8b-instruct:free',
                    'lmstudio' => 'qwen2.5-coder-7b-instruct',
                    'qwen' => function_exists('config') ? (config('harness.qwen_provider.model') ?: 'qwen-plus') : 'qwen-plus',
                    'qwen_cloud' => function_exists('config') ? (config('harness.qwen_provider.model') ?: 'qwen-plus') : 'qwen-plus',
                    default => 'llama3.2',
                };
            }
        }

        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * Return the effective model this client will use.
     * AgentLoop uses this to auto-detect Qwen/Gemma for prompt optimization.
     */
    public function getResolvedModel(): string
    {
        return $this->model;
    }

    /**
     * Send messages to the LLM backend via Laravel AI SDK.
     * Falls back to a direct Chat Completions call for providers that don't
     * support the OpenAI Responses API (e.g. LM Studio, OpenRouter).
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
        $resolvedModel = empty($model) ? $this->model : $model;

        // Detect if AiManager is mocked (unit testing) - use SDK path for mocks
        $isMockedTest = false;
        if (function_exists('app') && app()->bound(AiManager::class)) {
            $manager = app(AiManager::class);
            $isMockedTest = (interface_exists('Mockery\MockInterface') && $manager instanceof MockInterface)
                || (class_exists('PHPUnit\Framework\MockObject\MockObject') && $manager instanceof MockObject)
                || (interface_exists('PHPUnit\Framework\MockObject\MockObject') && $manager instanceof MockObject);
        }

        if (($this->provider === 'qwen' || $this->provider === 'qwen_cloud') && ! $isMockedTest) {
            $client = new QwenClient(defaultModel: $resolvedModel);

            return $client->chat($systemPrompt, $messages, $tools, $resolvedModel, $sessionId, $collector, $onChunk);
        }

        if (in_array($this->provider, self::CHAT_COMPLETIONS_ONLY_DRIVERS, true) && ! $isMockedTest) {
            return $this->chatCompletions($systemPrompt, $messages, $tools, $resolvedModel, $sessionId, $collector, $onChunk);
        }

        return $this->chatViaLaravelAi($systemPrompt, $messages, $tools, $resolvedModel, $sessionId, $collector, $onChunk);
    }

    /**
     * Call the provider directly using the standard Chat Completions API
     * (/v1/chat/completions). Used for LM Studio and OpenRouter which do
     * NOT support the OpenAI Responses API format that Laravel AI SDK sends.
     *
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $tools
     * @return array{content: string, tool_calls: array<mixed>}
     */
    protected function chatCompletions(
        string $systemPrompt,
        array $messages,
        array $tools,
        string $model,
        ?string $sessionId,
        ?AnalyticsCollectorInterface $collector,
        ?callable $onChunk
    ): array {
        $baseUrl = $this->provider === 'ollama' ? 'http://localhost:11434' : 'http://localhost:1234';
        $apiKey = 'lm-studio';

        if ($this->provider === 'qwen' || $this->provider === 'qwen_cloud') {
            $baseUrl = config('harness.qwen_provider.url')
                ?: (env('PHPKAIHARNESS_QWEN_URL') ?: (env('QWEN_URL') ?: env('DASHSCOPE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1')));
            $apiKey = config('harness.qwen_provider.api_key')
                ?: (env('PHPKAIHARNESS_QWEN_KEY') ?: (env('QWEN_API_KEY') ?: env('DASHSCOPE_API_KEY', '')));
        }

        if (function_exists('config') && app()->bound('config')) {
            $configUrl = rtrim((string) config("ai.providers.{$this->provider}.url", $baseUrl), '/');
            // Strip trailing /v1 since we append /v1/chat/completions ourselves
            $baseUrl = preg_replace('#/v1$#i', '', $configUrl) ?: $configUrl;
            $apiKey = (string) config("ai.providers.{$this->provider}.key", $apiKey);
        }

        $httpClient = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 300.0,
            'verify' => function_exists('config') && config('app.env') === 'local' ? false : true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
            ],
        ]);

        $compiledMessages = [];
        if (! empty($systemPrompt)) {
            $compiledMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $msg) {
            $role = $msg['role'];
            if ($role === 'tool') {
                $compiledMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $msg['tool_call_id'] ?? '',
                    'name' => $msg['name'] ?? '',
                    'content' => $msg['content'] ?? '',
                ];
            } elseif ($role === 'assistant' && ! empty($msg['tool_calls'])) {
                $rawToolCalls = [];
                foreach ($msg['tool_calls'] as $tc) {
                    $args = $tc['arguments'] ?? [];
                    if (is_array($args)) {
                        // Ollama requires the top-level arguments to be a JSON object, never
                        // an array. Cast to object so an empty array becomes {} and a
                        // populated list-style array does not silently break, while nested
                        // arrays (list parameters) are preserved.
                        $encodedArgs = empty($args)
                            ? '{}'
                            : json_encode((object) $args);
                    } else {
                        $encodedArgs = ($args === '' || $args === null) ? '{}' : (string) $args;
                    }

                    $rawToolCalls[] = [
                        'id' => $tc['id'] ?? uniqid('call_'),
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => $encodedArgs,
                        ],
                    ];
                }
                $compiledMessages[] = [
                    'role' => 'assistant',
                    'content' => $msg['content'] ?? null,
                    'tool_calls' => $rawToolCalls,
                ];
            } else {
                $compiledMessages[] = [
                    'role' => $role,
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        $payload = [
            'model' => $model,
            'messages' => $compiledMessages,
            'max_tokens' => 12000,
            'temperature' => 0.7,
            'top_p' => 0.8,
        ];
        if ($this->provider === 'qwen' || $this->provider === 'qwen_cloud' || $this->provider === 'lmstudio') {
            $payload['repetition_penalty'] = 1.1;
        }

        // qwen3 models require enable_thinking=true to produce proper responses
        if (str_starts_with($model, 'qwen3') || str_starts_with($model, 'qwq')) {
            $payload['enable_thinking'] = true;
        }

        if (! empty($tools)) {
            $payload['tools'] = $tools;
            // Ollama doesn't fully support tool_choice parameter; only add for non-Ollama providers
            if ($this->provider !== 'ollama') {
                $payload['tool_choice'] = 'auto';
            }
        }

        // Debug logging for Ollama issues
        if ($this->provider === 'ollama') {
            error_log('Ollama Request Payload: '.json_encode($payload, JSON_PRETTY_PRINT));
        }

        $startTime = microtime(true);

        try {
            $response = $httpClient->post('/v1/chat/completions', ['json' => $payload]);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $body = json_decode($response->getBody()->getContents(), true);

            if (! isset($body['choices'][0]['message'])) {
                throw new Exception('Malformed response from Chat Completions API: '.json_encode($body));
            }

            $choiceMessage = $body['choices'][0]['message'];
            $content = $choiceMessage['content'] ?? '';
            $reasoningContent = $choiceMessage['reasoning_content'] ?? '';

            // Qwen3.5+ models: content = actual answer, reasoning_content = thinking
            // Always prefer content (the answer). Only use reasoning_content as fallback.
            if (empty($content) && ! empty($reasoningContent)) {
                $content = $reasoningContent;
            }
            $content = $this->parseThinkingResponse($content);
            $rawToolCalls = $choiceMessage['tool_calls'] ?? [];

            $formattedToolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $fn = $tc['function'] ?? [];
                $argsRaw = $fn['arguments'] ?? '';
                $arguments = is_string($argsRaw) ? (json_decode($argsRaw, true) ?? []) : $argsRaw;
                $formattedToolCalls[] = [
                    'id' => $tc['id'] ?? uniqid('call_'),
                    'name' => $fn['name'] ?? '',
                    'arguments' => $arguments,
                ];
            }

            $usage = [
                'prompt_tokens' => $body['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $body['usage']['completion_tokens'] ?? 0,
            ];

            if ($collector && $sessionId) {
                $collector->recordLlmCall($sessionId, $model, $payload, $body, $durationMs, $usage);
            }

            if ($onChunk !== null && ! empty($content)) {
                $onChunk($content);
            }

            return ['content' => $content, 'tool_calls' => $formattedToolCalls];
        } catch (Exception $e) {
            throw new Exception('Laravel AI connection failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse and clean Qwen thinking/reasoning output.
     *
     * Qwen3.5+ models may output:
     * - Raw JSON: {"thought": "...thinking..."} followed by the actual response
     * - <thought>...</thought> tags wrapping reasoning
     *
     * This method strips the thinking portion and returns only the final response.
     */
    protected function parseThinkingResponse(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Strip Qwen3.5+ thinking blocks: <think>...</think>
        if (preg_match('/<think>(.*?)<\/think>/s', $content, $matches)) {
            $after = trim(str_replace($matches[0], '', $content));

            // If stripping leaves nothing, return original content (don't lose the response)
            return $after !== '' ? $after : $content;
        }

        // Strip unclosed <think> tag (model started thinking but didn't close it)
        if (preg_match('/<think>(.*)/s', $content, $matches)) {
            $after = trim(str_replace($matches[0], '', $content));

            return $after !== '' ? $after : $content;
        }

        // Strip <thought>...</thought> tags (legacy format)
        if (preg_match('/<thought>(.*?)<\/thought>/s', $content, $matches)) {
            $after = trim(str_replace($matches[0], '', $content));

            return $after !== '' ? $after : trim($matches[1]);
        }

        // Detect JSON thinking block: {"thought": "..."}
        $decoded = json_decode($content, true);
        if (is_array($decoded) && array_key_exists('thought', $decoded)) {
            if (! empty($decoded['response'])) {
                return (string) $decoded['response'];
            }
            $jsonEnd = strpos($content, '}') + 1;
            $after = trim(substr($content, $jsonEnd));

            return $after !== '' ? $after : '';
        }

        // Detect JSON response wrapper: {"text": "..."}
        if (is_array($decoded) && array_key_exists('text', $decoded) && count($decoded) === 1) {
            return (string) $decoded['text'];
        }

        return $content;
    }

    /**
     * Use the Laravel AI SDK TextGateway for providers that support
     * the OpenAI Responses API (e.g. Ollama, Gemini, native OpenAI).
     *
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $tools
     * @return array{content: string, tool_calls: array<mixed>}
     */
    protected function chatViaLaravelAi(
        string $systemPrompt,
        array $messages,
        array $tools,
        string $model,
        ?string $sessionId,
        ?AnalyticsCollectorInterface $collector,
        ?callable $onChunk
    ): array {
        $aiManager = app(AiManager::class);
        $textProvider = $aiManager->textProvider($this->provider);
        $textGateway = $textProvider->textGateway();

        $laravelMessages = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];
            if ($role === 'user') {
                $laravelMessages[] = new UserMessage($msg['content']);
            } elseif ($role === 'assistant') {
                $toolCalls = collect($msg['tool_calls'] ?? [])->map(fn ($tc) => new ToolCall(
                    $tc['id'] ?? uniqid('call_'),
                    $tc['name'],
                    $tc['arguments'] ?? []
                ));
                $laravelMessages[] = new AssistantMessage($msg['content'] ?? '', $toolCalls);
            } elseif ($role === 'tool') {
                $toolResults = collect([
                    new ToolResult(
                        $msg['tool_call_id'] ?? '',
                        $msg['name'] ?? '',
                        [],
                        $msg['content'] ?? ''
                    ),
                ]);
                $laravelMessages[] = new ToolResultMessage($toolResults);
            }
        }

        $laravelTools = array_map(fn ($t) => new RawSchemaLaravelTool($t), $tools);

        $startTime = microtime(true);

        try {
            $response = $textGateway->generateText(
                $textProvider,
                $model,
                $systemPrompt,
                $laravelMessages,
                $laravelTools,
                null,
                null,
                300
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $formattedToolCalls = [];
            foreach ($response->toolCalls as $index => $tc) {
                $formattedToolCalls[] = [
                    'id' => $tc->id ?? 'call_'.uniqid().'_'.$index,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments,
                ];
            }

            $usage = [
                'prompt_tokens' => $response->usage->prompt ?? 0,
                'completion_tokens' => $response->usage->completion ?? 0,
            ];

            if ($collector && $sessionId) {
                $collector->recordLlmCall($sessionId, $model, ['provider' => $this->provider, 'model' => $model, 'messages' => $messages], [
                    'text' => $response->text,
                    'tool_calls' => $formattedToolCalls,
                    'usage' => $usage,
                ], $durationMs, $usage);
            }

            if ($onChunk !== null && ! empty($response->text)) {
                $onChunk($response->text);
            }

            return ['content' => $response->text, 'tool_calls' => $formattedToolCalls];
        } catch (Exception $e) {
            throw new Exception('Laravel AI connection failed: '.$e->getMessage(), 0, $e);
        }
    }
}
