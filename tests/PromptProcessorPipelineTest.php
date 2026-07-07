<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Core\Prompt\PromptProcessorPipeline;
use PHPUnit\Framework\TestCase;

class PromptProcessorPipelineTest extends TestCase
{
    private function makeContext(): PromptContext
    {
        $client = new class implements LlmClientInterface
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
                return ['content' => 'ok', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return 'test-model';
            }
        };

        return new PromptContext(
            userPrompt: 'Hello',
            systemPrompt: 'You are helpful.',
            effectiveModel: 'test-model',
            sessionId: 'test-session',
            collector: null,
            llmClient: $client,
            agentName: 'TestAgent'
        );
    }

    public function test_pipeline_runs_enabled_stages_in_order(): void
    {
        $callOrder = [];

        $stage1 = new class($callOrder) implements PromptProcessorInterface
        {
            public function __construct(private array &$callOrder) {}

            public function isEnabled(PromptContext $context): bool
            {
                return true;
            }

            public function process(PromptContext $context): PromptContext
            {
                $this->callOrder[] = 'stage1';
                $context->effectiveUserPrompt .= ' [s1]';

                return $context;
            }
        };

        $stage2 = new class($callOrder) implements PromptProcessorInterface
        {
            public function __construct(private array &$callOrder) {}

            public function isEnabled(PromptContext $context): bool
            {
                return true;
            }

            public function process(PromptContext $context): PromptContext
            {
                $this->callOrder[] = 'stage2';
                $context->effectiveUserPrompt .= ' [s2]';

                return $context;
            }
        };

        $pipeline = new PromptProcessorPipeline([$stage1, $stage2]);
        $context = $pipeline->run($this->makeContext());

        $this->assertSame(['stage1', 'stage2'], $callOrder);
        $this->assertSame('Hello [s1] [s2]', $context->effectiveUserPrompt);
    }

    public function test_pipeline_skips_disabled_stages(): void
    {
        $stage1 = new class implements PromptProcessorInterface
        {
            public function isEnabled(PromptContext $context): bool
            {
                return true;
            }

            public function process(PromptContext $context): PromptContext
            {
                $context->effectiveUserPrompt .= ' [s1]';

                return $context;
            }
        };

        $stage2 = new class implements PromptProcessorInterface
        {
            public function isEnabled(PromptContext $context): bool
            {
                return false;
            }

            public function process(PromptContext $context): PromptContext
            {
                $context->effectiveUserPrompt .= ' [s2]';

                return $context;
            }
        };

        $pipeline = new PromptProcessorPipeline([$stage1, $stage2]);
        $context = $pipeline->run($this->makeContext());

        $this->assertSame('Hello [s1]', $context->effectiveUserPrompt);
    }

    public function test_pipeline_with_no_stages_returns_context_unchanged(): void
    {
        $pipeline = new PromptProcessorPipeline([]);
        $context = $pipeline->run($this->makeContext());

        $this->assertSame('Hello', $context->effectiveUserPrompt);
        $this->assertSame('You are helpful.', $context->optimizedSystemPrompt);
    }
}
