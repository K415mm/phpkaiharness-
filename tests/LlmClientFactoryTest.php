<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Llm\LlmClientFactory;
use Phpkaiharness\Llm\LmStudioClient;
use Phpkaiharness\Llm\OllamaClient;
use Phpkaiharness\Llm\OpenRouterClient;
use Phpkaiharness\Llm\QwenClient;
use PHPUnit\Framework\TestCase;

class LlmClientFactoryTest extends TestCase
{
    public function test_creates_ollama_client_by_default(): void
    {
        $factory = new LlmClientFactory;

        $client = $factory->make('ollama', 'gemma');

        $this->assertInstanceOf(OllamaClient::class, $client);
        $this->assertSame('gemma', $client->getResolvedModel());
    }

    public function test_unknown_provider_falls_back_to_ollama(): void
    {
        $factory = new LlmClientFactory;

        $client = $factory->make('does-not-exist', 'foo');

        $this->assertInstanceOf(OllamaClient::class, $client);
    }

    public function test_creates_lmstudio_client(): void
    {
        $factory = new LlmClientFactory;

        $client = $factory->make('lmstudio', 'my-model', ['url' => 'http://localhost:1234']);

        $this->assertInstanceOf(LmStudioClient::class, $client);
        $this->assertSame('my-model', $client->getResolvedModel());
    }

    public function test_creates_openrouter_client(): void
    {
        $factory = new LlmClientFactory;

        $client = $factory->make('openrouter', 'meta-llama/llama-3-8b-instruct', ['api_key' => 'test-key']);

        $this->assertInstanceOf(OpenRouterClient::class, $client);
        $this->assertSame('meta-llama/llama-3-8b-instruct', $client->getResolvedModel());
    }

    public function test_creates_qwen_client_for_both_aliases(): void
    {
        $factory = new LlmClientFactory;

        $qwen = $factory->make('qwen', 'qwen-plus', ['api_key' => 'k', 'url' => 'https://example.test/v1']);
        $qwenCloud = $factory->make('qwen_cloud', 'qwen-max', ['api_key' => 'k', 'url' => 'https://example.test/v1']);

        $this->assertInstanceOf(QwenClient::class, $qwen);
        $this->assertInstanceOf(QwenClient::class, $qwenCloud);
        $this->assertSame('qwen-plus', $qwen->getResolvedModel());
        $this->assertSame('qwen-max', $qwenCloud->getResolvedModel());
    }

    public function test_empty_model_uses_provider_default(): void
    {
        $factory = new LlmClientFactory;

        $client = $factory->make('ollama', '');

        $this->assertSame('hermes-3-llama-3-8b', $client->getResolvedModel());
    }

    public function test_supports_reports_known_providers(): void
    {
        $factory = new LlmClientFactory;

        $this->assertTrue($factory->supports('ollama'));
        $this->assertTrue($factory->supports('QWEN'));
        $this->assertTrue($factory->supports('laravel_ai'));
        $this->assertFalse($factory->supports('nonexistent'));
    }
}
