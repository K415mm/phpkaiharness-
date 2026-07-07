<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Llm\FailoverLlmClient;
use Phpkaiharness\Llm\LlmClientPipelineBuilder;
use Phpkaiharness\Llm\ThinkingBudgetLlmClient;
use PHPUnit\Framework\TestCase;

class LlmClientPipelineBuilderTest extends TestCase
{
    private function makeBaseClient(): LlmClientInterface
    {
        return new class implements LlmClientInterface
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
                return ['content' => 'base', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return 'base-model';
            }
        };
    }

    public function test_no_decoration_when_auto_decorate_disabled(): void
    {
        $builder = (new LlmClientPipelineBuilder)->fromConfig(['auto_decorate' => false]);
        $base = $this->makeBaseClient();

        $result = $builder->build($base);

        $this->assertSame($base, $result);
    }

    public function test_all_decorators_applied_by_default(): void
    {
        $builder = new LlmClientPipelineBuilder;
        $result = $builder->build($this->makeBaseClient());

        // Outermost should be ThinkingBudget (applied last in decorateSingle)
        $this->assertInstanceOf(ThinkingBudgetLlmClient::class, $result);
    }

    public function test_rate_limiting_skipped_when_disabled(): void
    {
        $builder = (new LlmClientPipelineBuilder)
            ->withRateLimiting(false)
            ->withPiiMasking(false)
            ->withThinkingBudget(true);

        $result = $builder->build($this->makeBaseClient());

        $this->assertInstanceOf(ThinkingBudgetLlmClient::class, $result);
    }

    public function test_all_disabled_returns_base(): void
    {
        $builder = (new LlmClientPipelineBuilder)
            ->withRateLimiting(false)
            ->withPiiMasking(false)
            ->withThinkingBudget(false)
            ->withFailover(false);

        $base = $this->makeBaseClient();
        $result = $builder->build($base);

        $this->assertSame($base, $result);
    }

    public function test_failover_wraps_decorated_clients(): void
    {
        $builder = (new LlmClientPipelineBuilder)
            ->withRateLimiting(false)
            ->withPiiMasking(false)
            ->withThinkingBudget(false)
            ->withFailover(true, [
                ['provider' => 'ollama', 'model' => 'gemma'],
                ['provider' => 'ollama', 'model' => 'llama3'],
            ]);

        $result = $builder->build($this->makeBaseClient());

        $this->assertInstanceOf(FailoverLlmClient::class, $result);
    }

    public function test_from_config_populates_all_settings(): void
    {
        $config = [
            'auto_decorate' => true,
            'rate_limiting' => ['enabled' => true, 'requests_per_minute' => 30, 'cooldown_ms' => 500],
            'pii_masking' => ['enabled' => true, 'patterns' => ['EMAIL' => '/test/']],
            'budget' => ['enabled' => true, 'max_tokens' => 5000],
            'failover' => ['enabled' => false, 'clients' => []],
        ];

        $builder = new LlmClientPipelineBuilder;
        $builder->fromConfig($config);

        // Verify via reflection that config was parsed
        $ref = new \ReflectionClass($builder);
        $rpm = $ref->getProperty('requestsPerMinute');
        $rpm->setAccessible(true);
        $this->assertSame(30, $rpm->getValue($builder));

        $budget = $ref->getProperty('maxTokensBudget');
        $budget->setAccessible(true);
        $this->assertSame(5000, $budget->getValue($builder));
    }
}
