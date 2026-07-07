<?php

namespace Phpkaiharness\Llm;

class ModelCatalog
{
    /**
     * Built-in model metadata registry.
     *
     * Covers the most common commercial and open-source models used in phpkaiharness.
     * Developers can extend or override via constructor injection.
     *
     * @var array<string, array{
     *     provider: string,
     *     max_tokens: int,
     *     supports_tools: bool,
     *     supports_streaming: bool,
     *     cost_per_1k_input: float,
     *     cost_per_1k_output: float
     * }>
     */
    protected array $catalog;

    /**
     * @param  array<string, array{
     *     provider: string,
     *     max_tokens: int,
     *     supports_tools: bool,
     *     supports_streaming: bool,
     *     cost_per_1k_input: float,
     *     cost_per_1k_output: float
     * }>  $overrides  Optional custom entries that will be merged on top of the built-in registry.
     */
    public function __construct(array $overrides = [])
    {
        $this->catalog = array_merge($this->defaults(), $overrides);
    }

    /**
     * Retrieve full metadata for a given model identifier.
     *
     * @return array{
     *     provider: string,
     *     max_tokens: int,
     *     supports_tools: bool,
     *     supports_streaming: bool,
     *     cost_per_1k_input: float,
     *     cost_per_1k_output: float
     * }|null
     */
    public function getMetadata(string $model): ?array
    {
        return $this->catalog[$model] ?? null;
    }

    /**
     * Register or override metadata for a specific model at runtime.
     *
     * @param  array{
     *     provider: string,
     *     max_tokens: int,
     *     supports_tools: bool,
     *     supports_streaming: bool,
     *     cost_per_1k_input: float,
     *     cost_per_1k_output: float
     * }  $metadata
     */
    public function register(string $model, array $metadata): self
    {
        $this->catalog[$model] = $metadata;

        return $this;
    }

    /**
     * Check whether a model supports tool calling.
     */
    public function supportsTools(string $model): bool
    {
        return $this->catalog[$model]['supports_tools'] ?? false;
    }

    /**
     * Check whether a model supports token streaming.
     */
    public function supportsStreaming(string $model): bool
    {
        return $this->catalog[$model]['supports_streaming'] ?? false;
    }

    /**
     * Return all registered model identifiers.
     *
     * @return array<string>
     */
    public function list(): array
    {
        return array_keys($this->catalog);
    }

    /**
     * Pick the cheapest model from the catalog that satisfies the given capability requirements.
     *
     * @param  bool  $requireTools  Only consider models that support tool calling.
     * @param  bool  $requireStreaming  Only consider models that support streaming.
     * @return string|null The model identifier, or null if none matches.
     */
    public function cheapest(bool $requireTools = false, bool $requireStreaming = false): ?string
    {
        $candidates = array_filter(
            $this->catalog,
            static function (array $meta) use ($requireTools, $requireStreaming): bool {
                if ($requireTools && ! $meta['supports_tools']) {
                    return false;
                }
                if ($requireStreaming && ! $meta['supports_streaming']) {
                    return false;
                }

                return true;
            }
        );

        if (empty($candidates)) {
            return null;
        }

        uasort($candidates, static function (array $a, array $b): int {
            $costA = $a['cost_per_1k_input'] + $a['cost_per_1k_output'];
            $costB = $b['cost_per_1k_input'] + $b['cost_per_1k_output'];

            return $costA <=> $costB;
        });

        return array_key_first($candidates);
    }

    /**
     * Built-in defaults for well-known models.
     *
     * @return array<string, array{
     *     provider: string,
     *     max_tokens: int,
     *     supports_tools: bool,
     *     supports_streaming: bool,
     *     cost_per_1k_input: float,
     *     cost_per_1k_output: float
     * }>
     */
    protected function defaults(): array
    {
        return [
            // OpenAI
            'gpt-4o' => [
                'provider' => 'openai',
                'max_tokens' => 128000,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.005,
                'cost_per_1k_output' => 0.015,
            ],
            'gpt-4o-mini' => [
                'provider' => 'openai',
                'max_tokens' => 128000,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.00015,
                'cost_per_1k_output' => 0.0006,
            ],
            'gpt-3.5-turbo' => [
                'provider' => 'openai',
                'max_tokens' => 16385,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.0005,
                'cost_per_1k_output' => 0.0015,
            ],
            // Anthropic
            'claude-3-5-sonnet-20241022' => [
                'provider' => 'anthropic',
                'max_tokens' => 200000,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.003,
                'cost_per_1k_output' => 0.015,
            ],
            'claude-3-haiku-20240307' => [
                'provider' => 'anthropic',
                'max_tokens' => 200000,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.00025,
                'cost_per_1k_output' => 0.00125,
            ],
            // Google
            'gemini-2.0-flash' => [
                'provider' => 'google',
                'max_tokens' => 1048576,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.0001,
                'cost_per_1k_output' => 0.0004,
            ],
            'gemini-1.5-pro' => [
                'provider' => 'google',
                'max_tokens' => 1048576,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.00125,
                'cost_per_1k_output' => 0.005,
            ],
            // Meta / Ollama (local — zero cost)
            'llama3.2' => [
                'provider' => 'ollama',
                'max_tokens' => 128000,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.0,
                'cost_per_1k_output' => 0.0,
            ],
            'gemma3:27b' => [
                'provider' => 'ollama',
                'max_tokens' => 8192,
                'supports_tools' => false,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.0,
                'cost_per_1k_output' => 0.0,
            ],
            // Mistral
            'mistral-large-latest' => [
                'provider' => 'mistral',
                'max_tokens' => 131072,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.002,
                'cost_per_1k_output' => 0.006,
            ],
            'mistral-small-latest' => [
                'provider' => 'mistral',
                'max_tokens' => 131072,
                'supports_tools' => true,
                'supports_streaming' => true,
                'cost_per_1k_input' => 0.0001,
                'cost_per_1k_output' => 0.0003,
            ],
        ];
    }
}
