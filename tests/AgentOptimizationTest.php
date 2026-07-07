<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\ToolInterface;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Optimize\ContextCompactor;
use Phpkaiharness\Optimize\Guardrails;
use Phpkaiharness\Optimize\SemanticCache;

class AgentOptimizationTest extends PhpkaiharnessTestCase
{
    /**
     * Test Guardrails safety validation.
     */
    public function test_guardrails_blocks_unsafe_arguments(): void
    {
        $guard = new Guardrails;

        // 1. Block command injection attempts
        $res = $guard->validate('test_tool', ['cmd' => 'ping 127.0.0.1; rm -rf /']);
        $this->assertIsString($res);
        $this->assertStringContainsString('Safety Violation', $res);

        // 2. Block piping
        $res = $guard->validate('test_tool', ['cmd' => 'ping 127.0.0.1 && cat /etc/passwd']);
        $this->assertIsString($res);

        // 3. Block dangerous commands
        $res = $guard->validate('test_tool', ['cmd' => 'sudo reboot']);
        $this->assertIsString($res);

        // 4. Accept safe arguments
        $res = $guard->validate('test_tool', ['cmd' => 'ping 127.0.0.1', 'count' => 4]);
        $this->assertTrue($res);
    }

    /**
     * Test ContextCompactor sliding window.
     */
    public function test_context_compactor_applies_sliding_window(): void
    {
        $compactor = new ContextCompactor('sliding_window', 4);

        $history = [
            ['role' => 'user', 'content' => 'Root query'],
            ['role' => 'assistant', 'content' => 'Turn 1'],
            ['role' => 'tool', 'name' => 'tool1', 'content' => 'res 1'],
            ['role' => 'assistant', 'content' => 'Turn 2'],
            ['role' => 'tool', 'name' => 'tool2', 'content' => 'res 2'],
            ['role' => 'assistant', 'content' => 'Turn 3'],
        ];

        $systemPrompt = 'system';
        $model = 'model';

        $compacted = $compactor->compact($history, $systemPrompt, $model);

        // Should keep root query, insert warning marker, and keep last 4 messages
        $this->assertCount(6, $compacted); // root (1) + marker (1) + last 4 (4) = 6
        $this->assertEquals('Root query', $compacted[0]['content']);
        $this->assertEquals('system', $compacted[1]['role']);
        $this->assertStringContainsString('Dropped', $compacted[1]['content']);
        $this->assertEquals('Turn 3', $compacted[5]['content']);
    }

    /**
     * Test SemanticCache exact and Levenshtein matches.
     */
    public function test_semantic_cache_matching(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_cache_'.uniqid().'.db';

        // Setup SQLite test database
        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS harness_sessions (
                id TEXT PRIMARY KEY,
                prompt TEXT NOT NULL,
                response TEXT DEFAULT '',
                method TEXT NOT NULL DEFAULT 'unknown',
                iterations INTEGER DEFAULT 0,
                total_duration_ms INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )
        ");

        // Seed successful completions
        $stmt = $pdo->prepare('INSERT INTO harness_sessions (id, prompt, response, method) VALUES (?, ?, ?, ?)');
        $stmt->execute(['sess1', 'what is the capital of France?', 'The capital of France is Paris.', 'cli-run']);
        $stmt->execute(['sess2', 'run ping check on localhost', 'Ping results show 100% reachability.', 'web-ui-run']);

        $cache = new SemanticCache($pdo, 0.85, $dbPath);

        // 1. Exact lookup
        $res = $cache->lookup('what is the capital of France?');
        $this->assertEquals('The capital of France is Paris.', $res);

        // 2. Fuzzy Levenshtein lookup (minor typo/case difference)
        $res = $cache->lookup('what is capital of France?');
        $this->assertEquals('The capital of France is Paris.', $res);

        // 3. Cache miss
        $res = $cache->lookup('who is the president of France?');
        $this->assertNull($res);

        // Cleanup
        unset($stmt);
        unset($cache);
        unset($pdo);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }

    /**
     * Test AgentLoop integrations with optimizations.
     */
    public function test_agent_loop_caching_and_guardrails_integration(): void
    {
        // Mock LLM Client
        $llmClientMock = new class implements LlmClientInterface
        {
            public int $callCount = 0;

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->callCount++;

                return [
                    'content' => 'LLM finished',
                    'tool_calls' => [],
                ];
            }

            public function getResolvedModel(): string
            {
                return 'test-model';
            }
        };

        $registry = new ToolRegistry;
        $loop = new AgentLoop($llmClientMock, $registry, 'system', 'model', 5);

        // 1. Setup guardrails that block anything with 'rm'
        $guard = new Guardrails(['/\\brm\\b/i']);
        $loop->setGuardrails($guard);

        // Add dummy tool
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'delete_records';
            }

            public function description(): string
            {
                return 'delete';
            }

            public function schema(): array
            {
                return [];
            }

            public function execute(array $args): string
            {
                return 'done';
            }
        };
        $registry->attach($tool);

        // Verify guardrail validation blocks unsafe arguments
        $blockedResult = $guard->validate('delete_records', ['cmd' => 'rm -rf']);
        $this->assertIsString($blockedResult);

        // 2. Test semantic cache mock
        $cacheMock = new class extends SemanticCache
        {
            public function __construct() {}

            public function lookup(string $prompt): ?string
            {
                if ($prompt === 'ping') {
                    return 'cached response';
                }

                return null;
            }
        };
        $loop->setSemanticCache($cacheMock);

        $history = [];
        $res = $loop->run('ping', $history);

        // Should hit cache and skip LLM call
        $this->assertEquals('cached response', $res);
        $this->assertEquals(0, $llmClientMock->callCount);
    }
}
