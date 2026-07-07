<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Optimize\CognitiveGraphMemory;

class CognitiveMemoryQualityTest extends PhpkaiharnessTestCase
{
    public function test_passes_quality_filter_rejects_empty(): void
    {
        $memory = new CognitiveGraphMemory;

        $this->assertFalse($memory->passesQualityFilter(''));
        $this->assertFalse($memory->passesQualityFilter('short'));
    }

    public function test_passes_quality_filter_rejects_uncertainty_patterns(): void
    {
        $memory = new CognitiveGraphMemory;

        $this->assertFalse($memory->passesQualityFilter('maybe the value was set'));
        $this->assertFalse($memory->passesQualityFilter('I think the client was created'));
        $this->assertFalse($memory->passesQualityFilter('TODO: implement this feature'));
        $this->assertFalse($memory->passesQualityFilter('this is a placeholder text'));
    }

    public function test_passes_quality_filter_rejects_markdown_only(): void
    {
        $memory = new CognitiveGraphMemory;

        $this->assertFalse($memory->passesQualityFilter('###'));
        $this->assertFalse($memory->passesQualityFilter('---'));
        $this->assertFalse($memory->passesQualityFilter('```'));
    }

    public function test_passes_quality_filter_accepts_valid_facts(): void
    {
        $memory = new CognitiveGraphMemory;

        $this->assertTrue($memory->passesQualityFilter('The client was created with ID 12345'));
        $this->assertTrue($memory->passesQualityFilter('Database settings were updated to use SSL'));
        $this->assertTrue($memory->passesQualityFilter('Allocation of 50 devices was assigned to the new client'));
    }

    public function test_passes_quality_filter_respects_custom_min_length(): void
    {
        // Test with default min_length=15
        $memory = new CognitiveGraphMemory;

        // 14 chars — should be rejected
        $this->assertFalse($memory->passesQualityFilter('valid fact!!!'));
        // 15+ chars — should pass
        $this->assertTrue($memory->passesQualityFilter('valid fact here'));
    }
}
