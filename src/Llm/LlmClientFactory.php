<?php

namespace Phpkaiharness\Llm;

use Phpkaiharness\Contracts\LlmClientInterface;

/**
 * Factory for creating base LLM clients from a provider identifier.
 *
 * Centralizes the provider => client mapping that was previously duplicated
 * across the telemetry controller and AgentLoop failover assembly. Adding a
 * new provider now requires changing only this class.
 *
 * Works standalone (no Laravel container required); individual clients resolve
 * their own credentials/URLs from env/config when not supplied via $options.
 */
class LlmClientFactory
{
    /**
     * Provider identifiers this factory can instantiate.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_PROVIDERS = [
        'ollama',
        'lmstudio',
        'openrouter',
        'qwen',
        'qwen_cloud',
        'laravel_ai',
    ];

    /**
     * Create a base LLM client for the given provider.
     *
     * @param  string  $provider  Provider identifier (e.g. "ollama", "openrouter").
     * @param  string  $model  Model identifier; empty falls back to the provider default.
     * @param  array{url?: string, api_key?: string, connection?: string}  $options
     *                                                                               Optional overrides. When omitted, clients resolve from env/config.
     */
    public function make(string $provider, string $model = '', array $options = []): LlmClientInterface
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);

        return match ($provider) {
            'lmstudio' => new LmStudioClient(
                ! empty($options['url']) ? $options['url'] : null,
                $model !== '' ? $model : 'lmstudio-community/gemma-2b-it-GGUF'
            ),
            'openrouter' => new OpenRouterClient(
                $options['api_key'] ?? (getenv('OPENROUTER_API_KEY') ?: ''),
                $model !== '' ? $model : 'meta-llama/llama-3-8b-instruct'
            ),
            'qwen', 'qwen_cloud' => new QwenClient(
                $options['api_key'] ?? null,
                $options['url'] ?? null,
                $model !== '' ? $model : 'qwen-plus'
            ),
            'laravel_ai' => new LaravelAiClient(
                $options['connection'] ?? 'ollama',
                $model
            ),
            default => new OllamaClient(
                $this->resolveOllamaUrl($options),
                $model !== '' ? $model : 'hermes-3-llama-3-8b'
            ),
        };
    }

    /**
     * Whether the given provider identifier is supported by this factory.
     */
    public function supports(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * Resolve the Ollama base URL from options, env, then default.
     *
     * @param  array{url?: string}  $options
     */
    private function resolveOllamaUrl(array $options): string
    {
        if (! empty($options['url'])) {
            return $options['url'];
        }

        $envUrl = getenv('PHPKAIHARNESS_URL');

        return $envUrl !== false && $envUrl !== '' ? $envUrl : 'http://localhost:11434';
    }
}
