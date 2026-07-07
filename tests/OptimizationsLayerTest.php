<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Contracts\SemanticMemoryInterface;
use Phpkaiharness\Optimize\Guardrails;
use Phpkaiharness\Optimize\SemanticCache;
use PHPUnit\Framework\TestCase;

class OptimizationsLayerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Guardrails: Palantir AIP-style Approvals & Checkpoints Tests
    // -------------------------------------------------------------------------

    public function test_guardrails_blocks_high_risk_tool_without_callback(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_database', 'execute_shell']);

        $result = $guardrails->validate('delete_database', ['id' => 123]);

        $this->assertIsString($result);
        $this->assertStringContainsString('Approval Required', $result);
        $this->assertStringContainsString('high-risk', $result);
    }

    public function test_guardrails_approves_high_risk_tool_with_approved_callback(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_database']);
        $guardrails->setApprovalCallback(static fn (string $tool, array $args): bool => true);

        $result = $guardrails->validate('delete_database', ['id' => 123]);

        $this->assertTrue($result);
    }

    public function test_guardrails_denies_high_risk_tool_when_callback_returns_false(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['execute_shell']);
        $guardrails->setApprovalCallback(static fn (string $tool, array $args): bool => false);

        $result = $guardrails->validate('execute_shell', ['command' => 'ls']);

        $this->assertIsString($result);
        $this->assertStringContainsString('Approval Denied', $result);
    }

    public function test_guardrails_approves_non_high_risk_tools_without_callback(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_database']);
        // No approval callback set

        $result = $guardrails->validate('read_data', ['id' => 123]);

        $this->assertTrue($result);
    }

    public function test_guardrails_high_risk_tool_pattern_matching(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_*', 'execute_*']);
        $guardrails->setApprovalCallback(static fn (): bool => true);

        $this->assertTrue($guardrails->validate('delete_user', ['id' => 1]));
        $this->assertTrue($guardrails->validate('delete_record', ['table' => 'orders']));
        $this->assertTrue($guardrails->validate('execute_query', ['sql' => 'SELECT 1']));
        $this->assertTrue($guardrails->validate('read_data', ['id' => 1])); // Not high risk
    }

    // -------------------------------------------------------------------------
    // Guardrails: Purpose-Based Scope Controls Tests
    // -------------------------------------------------------------------------

    public function test_guardrails_scope_blocks_unauthorized_tool(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setAuthorizedScopes(['read-only', 'analytics']);
        $guardrails->setToolScopeMap([
            'delete_*' => ['admin', 'write'],
            'modify_*' => ['write'],
        ]);

        $result = $guardrails->validate('delete_user', ['id' => 1]);

        $this->assertIsString($result);
        $this->assertStringContainsString('Scope Violation', $result);
        $this->assertStringContainsString('requires one of scopes: [admin, write]', $result);
        $this->assertStringContainsString('Agent authorized scopes: [read-only, analytics]', $result);
    }

    public function test_guardrails_scope_allows_authorized_tool(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setAuthorizedScopes(['admin', 'read-only']);
        $guardrails->setToolScopeMap([
            'delete_*' => ['admin', 'write'],
            'read_*' => ['read-only'],
        ]);

        $this->assertTrue($guardrails->validate('delete_user', ['id' => 1]));
        $this->assertTrue($guardrails->validate('read_user', ['id' => 1]));
    }

    public function test_guardrails_scope_allows_tool_when_any_required_scope_matches(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setAuthorizedScopes(['write']);
        $guardrails->setToolScopeMap([
            'modify_*' => ['admin', 'write'],
        ]);

        $this->assertTrue($guardrails->validate('modify_data', ['key' => 'value']));
    }

    public function test_guardrails_scope_skips_validation_when_no_scopes_configured(): void
    {
        $guardrails = new Guardrails;
        // No authorized scopes or tool scope map set

        $this->assertTrue($guardrails->validate('any_tool', ['data' => 'test']));
    }

    public function test_guardrails_scope_skips_validation_when_no_tool_map(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setAuthorizedScopes(['read-only']);
        // No tool scope map set

        $this->assertTrue($guardrails->validate('any_tool', ['data' => 'test']));
    }

    // -------------------------------------------------------------------------
    // Guardrails: Combined Validation Layers Tests
    // -------------------------------------------------------------------------

    public function test_guardrails_all_layers_work_together(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_database']);
        $guardrails->setApprovalCallback(static fn (): bool => true);
        $guardrails->setAuthorizedScopes(['admin']);
        $guardrails->setToolScopeMap([
            'delete_*' => ['admin'],
        ]);

        // Should pass all three layers: patterns (safe), scope (authorized), approval (granted)
        $this->assertTrue($guardrails->validate('delete_database', ['id' => 1]));
    }

    public function test_guardrails_pattern_violation_triggered_before_scope(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setAuthorizedScopes(['admin']);
        $guardrails->setToolScopeMap([
            'test_tool' => ['admin'],
        ]);

        // Pattern violation (contains shell syntax)
        $result = $guardrails->validate('test_tool', ['command' => 'rm -rf /']);

        $this->assertIsString($result);
        $this->assertStringContainsString('Safety Violation', $result);
    }

    public function test_guardrails_scope_violation_triggered_before_approval(): void
    {
        $guardrails = new Guardrails;
        $guardrails->setHighRiskTools(['delete_user']);
        $guardrails->setApprovalCallback(static fn (): bool => true); // Would approve
        $guardrails->setAuthorizedScopes(['read-only']);
        $guardrails->setToolScopeMap([
            'delete_*' => ['admin'],
        ]);

        $result = $guardrails->validate('delete_user', ['id' => 1]);

        $this->assertIsString($result);
        $this->assertStringContainsString('Scope Violation', $result);
    }

    // -------------------------------------------------------------------------
    // SemanticCache: Vector-Based Semantic Matching Tests
    // -------------------------------------------------------------------------

    public function test_semantic_cache_uses_vector_search_when_memory_available(): void
    {
        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public bool $searchCalled = false;

            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                $this->searchCalled = true;

                return [
                    ['text' => 'Cached response for similar query', 'source' => 'test', 'score' => 0.95],
                ];
            }

            public function addMemory(string $text, array $embedding, string $source): void {}
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        $result = $cache->lookup('What is the weather today?');

        $this->assertTrue($semanticMemory->searchCalled);
        $this->assertEquals('Cached response for similar query', $result);
    }

    public function test_semantic_cache_returns_null_when_vector_search_finds_no_match(): void
    {
        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                return []; // No matches
            }

            public function addMemory(string $text, array $embedding, string $source): void {}
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        // Without a PDO database, this should return null after semantic search fails
        $result = $cache->lookup('Some random query');

        $this->assertNull($result);
    }

    public function test_semantic_cache_ignores_low_score_vector_matches(): void
    {
        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                // Return result below default threshold of 0.88
                return [
                    ['text' => 'Weak match', 'source' => 'test', 'score' => 0.50],
                ];
            }

            public function addMemory(string $text, array $embedding, string $source): void {}
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        // Without a PDO database, this should return null after semantic search finds low score
        $result = $cache->lookup('Query');

        $this->assertNull($result);
    }

    public function test_semantic_cache_stores_to_memory_when_available(): void
    {
        $capturedEmbedding = null;
        $capturedText = null;

        $semanticMemory = new class($capturedEmbedding, $capturedText) implements SemanticMemoryInterface
        {
            public function __construct(private ?array &$capturedEmb, private ?string &$capturedText) {}

            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                return [];
            }

            public function addMemory(string $text, array $embedding, string $source): void
            {
                $this->capturedEmb = $embedding;
                $this->capturedText = $text;
            }
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        $cache->store(
            prompt: 'What is the capital of France?',
            response: 'Paris is the capital of France.',
            embedding: [0.1, 0.2, 0.3, 0.4]
        );

        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $capturedEmbedding);
        $this->assertEquals('Paris is the capital of France.', $capturedText);
    }

    public function test_semantic_cache_store_is_noop_without_memory(): void
    {
        $cache = new SemanticCache; // No semantic memory

        // Should not throw
        $cache->store('prompt', 'response', [0.1, 0.2]);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_semantic_cache_setter_works(): void
    {
        $cache = new SemanticCache; // No semantic memory initially

        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                return [['text' => 'Found', 'source' => 'test', 'score' => 0.99]];
            }

            public function addMemory(string $text, array $embedding, string $source): void {}
        };

        $cache->setSemanticMemory($semanticMemory);

        $result = $cache->lookup('query');
        $this->assertEquals('Found', $result);
    }

    public function test_semantic_cache_gracefully_handles_memory_exceptions(): void
    {
        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                throw new \Exception('Vector DB connection failed');
            }

            public function addMemory(string $text, array $embedding, string $source): void {}
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        // Should not throw, return null instead
        $result = $cache->lookup('query');

        $this->assertNull($result);
    }

    public function test_semantic_cache_store_gracefully_handles_exceptions(): void
    {
        $semanticMemory = new class implements SemanticMemoryInterface
        {
            public function search(string $query, float $threshold = 0.30, int $limit = 3): array
            {
                return [];
            }

            public function addMemory(string $text, array $embedding, string $source): void
            {
                throw new \Exception('Storage failed');
            }
        };

        $cache = new SemanticCache(semanticMemory: $semanticMemory);

        // Should not throw
        $cache->store('prompt', 'response', [0.1, 0.2]);

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Legacy tests to ensure backward compatibility
    // -------------------------------------------------------------------------

    public function test_guardrails_original_pattern_validation_still_works(): void
    {
        $guardrails = new Guardrails;

        // Command injection should be blocked
        $result = $guardrails->validate('safe_tool', ['cmd' => 'ls; rm -rf /']);
        $this->assertIsString($result);
        $this->assertStringContainsString('Safety Violation', $result);

        // Safe commands should pass
        $this->assertTrue($guardrails->validate('safe_tool', ['cmd' => 'ls']));
    }
}
