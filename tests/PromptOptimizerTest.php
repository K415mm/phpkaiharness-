<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Optimize\ModelPromptOptimizer;
use PHPUnit\Framework\TestCase;

class PromptOptimizerTest extends TestCase
{
    public function test_qwen_optimization_appends_thought_rules(): void
    {
        $optimizer = new ModelPromptOptimizer;
        $systemPrompt = 'You are a network security scanner.';
        $userPrompt = 'Scan target.';

        $optimized = $optimizer->optimize($systemPrompt, $userPrompt, 'qwen-3.5-9b');

        $this->assertStringContainsString('You are a network security scanner.', $optimized['system']);
        $this->assertStringContainsString('[QWEN OPTIMIZATION PROTOCOL]', $optimized['system']);
        $this->assertStringContainsString('<thought>...</thought>', $optimized['system']);
        $this->assertEquals('Scan target.', $optimized['user']);
    }

    public function test_gemma_optimization_appends_clean_json_rules(): void
    {
        $optimizer = new ModelPromptOptimizer;
        $systemPrompt = 'Verify ping on host.';
        $userPrompt = 'Ping Google.';

        $optimized = $optimizer->optimize($systemPrompt, $userPrompt, 'gemma-4-it');

        $this->assertStringContainsString('Verify ping on host.', $optimized['system']);
        $this->assertStringContainsString('[GEMMA OPTIMIZATION PROTOCOL]', $optimized['system']);
        $this->assertStringContainsString('clean JSON objects', $optimized['system']);
        $this->assertEquals('Ping Google.', $optimized['user']);
    }

    public function test_other_models_remain_unchanged(): void
    {
        $optimizer = new ModelPromptOptimizer;
        $systemPrompt = 'Translate english to spanish.';
        $userPrompt = 'Hello.';

        $optimized = $optimizer->optimize($systemPrompt, $userPrompt, 'llama-3-8b');

        $this->assertEquals($systemPrompt, $optimized['system']);
        $this->assertEquals($userPrompt, $optimized['user']);
    }
}
