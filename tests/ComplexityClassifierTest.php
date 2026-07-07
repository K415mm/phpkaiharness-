<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Optimize\ComplexityClassifier;

class ComplexityClassifierTest extends PhpkaiharnessTestCase
{
    public function test_simple_domain_classification(): void
    {
        $prompt = 'Hello, tell me a joke';
        $domain = ComplexityClassifier::classify($prompt);
        $this->assertEquals(ComplexityClassifier::DOMAIN_SIMPLE, $domain);
    }

    public function test_complicated_domain_with_entity_keywords(): void
    {
        $prompt = 'Can you show me the pricing details of client 3?';
        $domain = ComplexityClassifier::classify($prompt);
        $this->assertEquals(ComplexityClassifier::DOMAIN_COMPLICATED, $domain);
    }

    public function test_complicated_domain_with_registered_tools(): void
    {
        $prompt = 'How is the weather?';
        $registeredTools = ['GetWeatherInfoTool'];
        $domain = ComplexityClassifier::classify($prompt, $registeredTools);
        $this->assertEquals(ComplexityClassifier::DOMAIN_COMPLICATED, $domain);
    }

    public function test_complex_domain_with_mutating_tool(): void
    {
        $prompt = 'Check current system status';
        $registeredTools = ['UpdateGlobalSettingTool'];
        $domain = ComplexityClassifier::classify($prompt, $registeredTools);
        $this->assertEquals(ComplexityClassifier::DOMAIN_COMPLEX, $domain);
    }

    public function test_complex_domain_with_mutating_prompt_keywords(): void
    {
        $prompt = 'simulate profit for scenarios 2 and 3';
        $domain = ComplexityClassifier::classify($prompt);
        $this->assertEquals(ComplexityClassifier::DOMAIN_COMPLEX, $domain);
    }
}
