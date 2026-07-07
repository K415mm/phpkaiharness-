<?php

namespace Phpkaiharness\Tests;

use Exception;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Llm\FailoverLlmClient;
use Phpkaiharness\Llm\ModelCatalog;
use Phpkaiharness\Llm\PiiMaskingLlmClient;
use Phpkaiharness\Llm\RateLimitedLlmClient;
use PHPUnit\Framework\TestCase;

class LlmClientLayerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ModelCatalog Tests
    // -------------------------------------------------------------------------

    public function test_model_catalog_returns_known_model_metadata(): void
    {
        $catalog = new ModelCatalog;

        $meta = $catalog->getMetadata('gpt-4o');

        $this->assertNotNull($meta);
        $this->assertEquals('openai', $meta['provider']);
        $this->assertTrue($meta['supports_tools']);
        $this->assertTrue($meta['supports_streaming']);
        $this->assertEquals(128000, $meta['max_tokens']);
    }

    public function test_model_catalog_returns_null_for_unknown_model(): void
    {
        $catalog = new ModelCatalog;

        $this->assertNull($catalog->getMetadata('unknown-model-xyz'));
    }

    public function test_model_catalog_register_adds_custom_model(): void
    {
        $catalog = new ModelCatalog;
        $catalog->register('my-custom-model', [
            'provider' => 'custom',
            'max_tokens' => 4096,
            'supports_tools' => false,
            'supports_streaming' => false,
            'cost_per_1k_input' => 0.001,
            'cost_per_1k_output' => 0.002,
        ]);

        $meta = $catalog->getMetadata('my-custom-model');

        $this->assertNotNull($meta);
        $this->assertEquals('custom', $meta['provider']);
    }

    public function test_model_catalog_cheapest_returns_local_zero_cost_model(): void
    {
        $catalog = new ModelCatalog;

        $cheapest = $catalog->cheapest();

        $this->assertNotNull($cheapest);
        $meta = $catalog->getMetadata($cheapest);
        $this->assertEquals(0.0, $meta['cost_per_1k_input'] + $meta['cost_per_1k_output']);
    }

    public function test_model_catalog_cheapest_with_tool_requirement(): void
    {
        $catalog = new ModelCatalog;

        $cheapest = $catalog->cheapest(requireTools: true);

        $this->assertNotNull($cheapest);
        $this->assertTrue($catalog->supportsTools($cheapest));
    }

    public function test_model_catalog_list_returns_all_registered_models(): void
    {
        $catalog = new ModelCatalog;

        $list = $catalog->list();

        $this->assertIsArray($list);
        $this->assertContains('gpt-4o', $list);
        $this->assertContains('llama3.2', $list);
        $this->assertContains('claude-3-5-sonnet-20241022', $list);
    }

    // -------------------------------------------------------------------------
    // FailoverLlmClient Tests
    // -------------------------------------------------------------------------

    public function test_failover_uses_primary_client_on_success(): void
    {
        $primary = $this->makeMockClient('Primary response');
        $fallback = $this->makeMockClient('Fallback response');

        $failover = new FailoverLlmClient([$primary, $fallback]);
        $result = $failover->chat('system', [['role' => 'user', 'content' => 'hello']]);

        $this->assertEquals('Primary response', $result['content']);
    }

    public function test_failover_falls_back_when_primary_throws(): void
    {
        $primary = $this->makeFailingClient('Connection refused');
        $fallback = $this->makeMockClient('Fallback response');

        $failover = new FailoverLlmClient([$primary, $fallback]);
        $result = $failover->chat('system', [['role' => 'user', 'content' => 'hello']]);

        $this->assertEquals('Fallback response', $result['content']);
    }

    public function test_failover_throws_when_all_clients_fail(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/all 2 client\(s\) failed/');

        $client1 = $this->makeFailingClient('Error A');
        $client2 = $this->makeFailingClient('Error B');

        $failover = new FailoverLlmClient([$client1, $client2]);
        $failover->chat('system', [['role' => 'user', 'content' => 'hi']]);
    }

    public function test_failover_with_three_clients_uses_third_on_double_failure(): void
    {
        $client1 = $this->makeFailingClient('Timeout');
        $client2 = $this->makeFailingClient('Rate limited');
        $client3 = $this->makeMockClient('Third client worked');

        $failover = new FailoverLlmClient([$client1, $client2, $client3]);
        $result = $failover->chat('system', [['role' => 'user', 'content' => 'query']]);

        $this->assertEquals('Third client worked', $result['content']);
    }

    // -------------------------------------------------------------------------
    // PiiMaskingLlmClient Tests
    // -------------------------------------------------------------------------

    public function test_pii_masking_redacts_email_in_outbound_prompt(): void
    {
        $capturedMessages = [];

        $spy = new class($capturedMessages) implements LlmClientInterface
        {
            public function __construct(private array &$captured) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->captured = $messages;

                return ['content' => 'No PII in this response', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $masker = new PiiMaskingLlmClient($spy);
        $masker->chat('system', [['role' => 'user', 'content' => 'Contact me at john@example.com please']]);

        $this->assertStringNotContainsString('john@example.com', $capturedMessages[0]['content']);
        $this->assertStringContainsString('[EMAIL_1]', $capturedMessages[0]['content']);
    }

    public function test_pii_masking_restores_email_in_response(): void
    {
        $inner = new class implements LlmClientInterface
        {
            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                return ['content' => 'Please reply to [EMAIL_1] for support.', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $masker = new PiiMaskingLlmClient($inner);
        $result = $masker->chat('system', [['role' => 'user', 'content' => 'Contact us at support@acme.com']]);

        $this->assertStringContainsString('support@acme.com', $result['content']);
        $this->assertStringNotContainsString('[EMAIL_1]', $result['content']);
    }

    public function test_pii_masking_redacts_ip_address(): void
    {
        $capturedSystem = '';

        $spy = new class($capturedSystem) implements LlmClientInterface
        {
            public function __construct(private string &$captured) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->captured = $systemPrompt;

                return ['content' => 'ok', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $masker = new PiiMaskingLlmClient($spy);
        $masker->chat('Server at 192.168.1.1 is down', []);

        $this->assertStringNotContainsString('192.168.1.1', $capturedSystem);
        $this->assertStringContainsString('[IP_1]', $capturedSystem);
    }

    public function test_pii_masking_passes_through_clean_content_unchanged(): void
    {
        $capturedContent = '';

        $spy = new class($capturedContent) implements LlmClientInterface
        {
            public function __construct(private string &$captured) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->captured = $messages[0]['content'] ?? '';

                return ['content' => 'All good', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $masker = new PiiMaskingLlmClient($spy);
        $masker->chat('system', [['role' => 'user', 'content' => 'What is the weather today?']]);

        $this->assertEquals('What is the weather today?', $capturedContent);
    }

    // -------------------------------------------------------------------------
    // RateLimitedLlmClient Tests
    // -------------------------------------------------------------------------

    public function test_rate_limited_client_passes_through_normally(): void
    {
        $inner = $this->makeMockClient('Rate limited response');

        $rateLimited = new RateLimitedLlmClient($inner, 60, 0);
        $result = $rateLimited->chat('system', [['role' => 'user', 'content' => 'hi']]);

        $this->assertEquals('Rate limited response', $result['content']);
    }

    public function test_rate_limited_client_tracks_request_timestamps(): void
    {
        $callCount = 0;

        $inner = new class($callCount) implements LlmClientInterface
        {
            public function __construct(private int &$count) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->count++;

                return ['content' => 'ok', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $rateLimited = new RateLimitedLlmClient($inner, 100, 0);

        $rateLimited->chat('s', []);
        $rateLimited->chat('s', []);
        $rateLimited->chat('s', []);

        $this->assertEquals(3, $callCount);
    }

    // -------------------------------------------------------------------------
    // Decorator Composition Tests
    // -------------------------------------------------------------------------

    public function test_decorator_stack_composes_correctly(): void
    {
        $base = $this->makeMockClient('Base response from server at 10.0.0.1');

        $composed = new PiiMaskingLlmClient(
            new RateLimitedLlmClient(
                new FailoverLlmClient([$base]),
                requestsPerMinute: 100,
                cooldownMs: 0
            )
        );

        $result = $composed->chat(
            'System with IP 10.0.0.1',
            [['role' => 'user', 'content' => 'Contact admin@corp.com']]
        );

        $this->assertNotNull($result['content']);
        $this->assertIsArray($result['tool_calls']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeMockClient(string $content): LlmClientInterface
    {
        return new class($content) implements LlmClientInterface
        {
            public function __construct(private readonly string $response) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                if ($onChunk !== null) {
                    $onChunk($this->response);
                }

                return ['content' => $this->response, 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return 'mock-model';
            }
        };
    }

    private function makeFailingClient(string $message): LlmClientInterface
    {
        return new class($message) implements LlmClientInterface
        {
            public function __construct(private readonly string $errorMessage) {}

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                throw new Exception($this->errorMessage);
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };
    }
}
