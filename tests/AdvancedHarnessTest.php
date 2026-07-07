<?php

namespace Phpkaiharness\Tests;

use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Http\Middleware\CompressContextMiddleware;
use Phpkaiharness\Http\Middleware\EnvironmentBootstrapMiddleware;
use Phpkaiharness\Llm\ThinkingBudgetLlmClient;
use Phpkaiharness\Optimize\CognitiveGraphMemory;
use Phpkaiharness\Optimize\DraftVerificationOrchestration;
use Phpkaiharness\Optimize\OntologicalContextInjector;
use Phpkaiharness\Tools\QueryGraphMemoryTool;

class AdvancedHarnessTest extends PhpkaiharnessTestCase
{
    /**
     * Test Environment Bootstrapping Middleware.
     */
    public function test_environment_bootstrapping_middleware_injects_snapshot(): void
    {
        $dummyAgent = new class implements Agent
        {
            use Promptable;

            public function instructions(): \Stringable|string
            {
                return 'Base instructions';
            }
        };

        $dummyProvider = new class implements TextProvider
        {
            public function prompt(AgentPrompt $prompt): AgentResponse
            {
                return new AgentResponse('', '', new Usage, new Meta('', ''));
            }

            public function stream(AgentPrompt $prompt): StreamableAgentResponse
            {
                $stream = new class extends LazyCollection
                {
                    public function getIterator(): \Traversable
                    {
                        return new \ArrayIterator([]);
                    }
                };

                return new StreamableAgentResponse('', $stream, new Meta('', ''));
            }

            public function textGateway(): TextGateway
            {
                return new class implements TextGateway
                {
                    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
                    {
                        return new TextResponse('', new Usage, new Meta('', ''));
                    }

                    public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): \Generator
                    {
                        yield '';
                    }

                    public function onToolInvocation(\Closure $invoking, \Closure $invoked): self
                    {
                        return $this;
                    }
                };
            }

            public function useTextGateway(TextGateway $gateway): self
            {
                return $this;
            }

            public function defaultTextModel(): string
            {
                return 'dummy';
            }

            public function cheapestTextModel(): string
            {
                return 'dummy';
            }

            public function smartestTextModel(): string
            {
                return 'dummy';
            }
        };

        $prompt = new AgentPrompt($dummyAgent, 'Base user prompt', [], $dummyProvider, 'gemma');

        config(['harness.bootstrap.enabled' => true]);

        $middleware = new EnvironmentBootstrapMiddleware;
        $bootstrapped = $middleware->handle($prompt, fn ($p) => $p);

        $this->assertStringContainsString('[ENVIRONMENT SNAPSHOT]', $bootstrapped->prompt);
        $this->assertStringContainsString('Operating System:', $bootstrapped->prompt);
        $this->assertStringContainsString('PHP Version:', $bootstrapped->prompt);
        $this->assertStringContainsString('Base user prompt', $bootstrapped->prompt);
    }

    /**
     * Test Context Compression Middleware (Comment stripping and signature extraction).
     */
    public function test_context_compression_middleware_strips_comments_and_extracts_signatures(): void
    {
        $dummyAgent = new class implements Agent
        {
            use Promptable;

            public function instructions(): \Stringable|string
            {
                return '';
            }
        };

        $dummyProvider = new class implements TextProvider
        {
            public function prompt(AgentPrompt $prompt): AgentResponse
            {
                return new AgentResponse('', '', new Usage, new Meta('', ''));
            }

            public function stream(AgentPrompt $prompt): StreamableAgentResponse
            {
                $stream = new class extends LazyCollection
                {
                    public function getIterator(): \Traversable
                    {
                        return new \ArrayIterator([]);
                    }
                };

                return new StreamableAgentResponse('', $stream, new Meta('', ''));
            }

            public function textGateway(): TextGateway
            {
                return new class implements TextGateway
                {
                    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
                    {
                        return new TextResponse('', new Usage, new Meta('', ''));
                    }

                    public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): \Generator
                    {
                        yield '';
                    }

                    public function onToolInvocation(\Closure $invoking, \Closure $invoked): self
                    {
                        return $this;
                    }
                };
            }

            public function useTextGateway(TextGateway $gateway): self
            {
                return $this;
            }

            public function defaultTextModel(): string
            {
                return 'dummy';
            }

            public function cheapestTextModel(): string
            {
                return 'dummy';
            }

            public function smartestTextModel(): string
            {
                return 'dummy';
            }
        };

        // Write a mock code file with comments
        $tempDir = sys_get_temp_dir();
        $codePath = $tempDir.DIRECTORY_SEPARATOR.'test_code_'.uniqid().'.php';
        $codeContent = <<<'PHP'
<?php
// Single line comment
class MyTestClass {
    /**
     * Multi-line docblock
     */
    public function helloWorld() {
        // Output hello
        echo "Hello!";
    }
}
PHP;
        file_put_contents($codePath, $codeContent);

        $attachment = new LocalDocument($codePath, 'text/x-php');
        $attachment->as('test_code.php');

        $prompt = new AgentPrompt($dummyAgent, 'Check the code', [$attachment], $dummyProvider, 'gemma');

        // Set compression threshold high to trigger normal comment-stripping (not signature extraction)
        config(['harness.compression.line_threshold' => 150]);

        $middleware = new CompressContextMiddleware;
        $compressed = $middleware->handle($prompt, fn ($p) => $p);

        $this->assertCount(1, $compressed->attachments);
        $compressedDoc = $compressed->attachments[0];
        $this->assertInstanceOf(LocalDocument::class, $compressedDoc);

        $compressedContent = file_get_contents($compressedDoc->path);

        // Assert comment noise is gone
        $this->assertStringNotContainsString('// Single line comment', $compressedContent);
        $this->assertStringNotContainsString('Multi-line docblock', $compressedContent);
        $this->assertStringContainsString('class MyTestClass', $compressedContent);
        $this->assertStringContainsString('public function helloWorld', $compressedContent);

        // Now set line threshold low (e.g. 5 lines) to trigger JIT signature-only representation
        config(['harness.compression.line_threshold' => 5]);
        $compressed2 = $middleware->handle($prompt, fn ($p) => $p);
        $compressedContent2 = file_get_contents($compressed2->attachments[0]->path);

        $this->assertStringContainsString('[JIT SIGNATURE REPRESENTATION]', $compressedContent2);
        $this->assertStringContainsString('class MyTestClass', $compressedContent2);
        $this->assertStringContainsString('public function helloWorld', $compressedContent2);
        $this->assertStringContainsString('{ ... }', $compressedContent2);
        $this->assertStringNotContainsString('echo "Hello!"', $compressedContent2); // Body collapsed

        // Cleanup
        unlink($codePath);
        if (file_exists($compressedDoc->path)) {
            unlink($compressedDoc->path);
        }
        if (file_exists($compressed2->attachments[0]->path)) {
            unlink($compressed2->attachments[0]->path);
        }
    }

    /**
     * Test Thinking Budget LLM Client warning injection when tokens exceed budget.
     */
    public function test_thinking_budget_llm_client_injects_warning(): void
    {
        $innerMock = new class implements LlmClientInterface
        {
            public string $receivedSystemPrompt = '';

            public function chat(
                string $systemPrompt,
                array $messages,
                array $tools = [],
                string $model = '',
                ?string $sessionId = null,
                ?AnalyticsCollectorInterface $collector = null,
                ?callable $onChunk = null
            ): array {
                $this->receivedSystemPrompt = $systemPrompt;

                return ['content' => 'done'];
            }

            public function getResolvedModel(): string
            {
                return 'mock';
            }
        };

        // Create temporary DB to mock historical token counts
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_budget_'.uniqid().'.db';
        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS harness_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                type TEXT NOT NULL,
                tokens_prompt INTEGER DEFAULT 0,
                tokens_completion INTEGER DEFAULT 0
            )
        ');

        // Seed some tokens
        $stmt = $pdo->prepare("INSERT INTO harness_details (session_id, type, tokens_prompt, tokens_completion) VALUES (?, 'llm_call', ?, ?)");
        $stmt->execute(['sess-budget-123', 500, 600]); // Total 1100 tokens

        config(['harness.cache.db_path' => $dbPath]);
        config(['harness.budget.max_tokens' => 1000]); // Limit 1000, usage is 1100

        $client = new ThinkingBudgetLlmClient($innerMock, 1000);
        $client->chat('Base instructions', [], [], 'mock', 'sess-budget-123');

        // Budget exceeded, should append warning
        $this->assertStringContainsString('SYSTEM BUDGET WARNING', $innerMock->receivedSystemPrompt);
        $this->assertStringContainsString('1100 tokens, which exceeds your thinking budget of 1000', $innerMock->receivedSystemPrompt);

        // Check when under budget
        $innerMock->receivedSystemPrompt = '';
        config(['harness.budget.max_tokens' => 2000]); // Limit 2000, usage 1100
        $client->chat('Base instructions', [], [], 'mock', 'sess-budget-123');

        $this->assertStringNotContainsString('SYSTEM BUDGET WARNING', $innerMock->receivedSystemPrompt);
        $this->assertEquals('Base instructions', $innerMock->receivedSystemPrompt);

        // Cleanup
        unset($stmt);
        unset($pdo);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }

    /**
     * Test CognitiveGraphMemory extraction and SQL storage.
     */
    public function test_cognitive_graph_memory_extracts_and_stores_facts(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_facts_'.uniqid().'.db';

        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS harness_facts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                fact TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $collectorMock = new class($pdo) implements AnalyticsCollectorInterface
        {
            public array $loggedEvents = [];

            public function __construct(protected \PDO $pdo) {}

            public function startSession(
                string $sessionId,
                string $prompt,
                string $method,
                ?string $parentSessionId = null,
                int $interactionIndex = 0,
                ?string $rootSessionId = null,
                ?string $requestId = null,
                string $sessionType = 'interaction'
            ): void {}

            public function recordLlmCall(string $sessionId, string $model, array $payload, array $response, int $durationMs, array $usage): void {}

            public function recordToolCall(string $sessionId, string $toolName, array $arguments, string $result, int $durationMs): void {}

            public function endSession(string $sessionId, string $response, int $totalDurationMs, int $iterations): void {}

            public function recordEvent(string $sessionId, string $type, string $name, array $payload, string $response, int $durationMs = 0): void
            {
                $this->loggedEvents[] = [$type, $name, $response];
            }

            public function recordFact(string $sessionId, string $fact): void
            {
                $stmt = $this->pdo->prepare('INSERT INTO harness_facts (session_id, fact) VALUES (?, ?)');
                $stmt->execute([$sessionId, $fact]);
            }
        };

        $llmClientMock = new class implements LlmClientInterface
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
                return [
                    'content' => "Client Acme Corp has been successfully created in the system.\nGlobal SIEM pricing updated to 25.",
                ];
            }

            public function getResolvedModel(): string
            {
                return 'mock';
            }
        };

        config(['harness.cognitive_memory.enabled' => true]);

        $cognitiveMemory = new CognitiveGraphMemory;
        $cognitiveMemory->extractAndStore(
            'sess-facts-123',
            'create client Acme Corp and set SIEM price to 25',
            'Successfully completed.',
            $llmClientMock,
            $collectorMock
        );

        // Verify facts are in DB
        $stmt = $pdo->query("SELECT * FROM harness_facts WHERE session_id = 'sess-facts-123'");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Acme Corp', $rows[0]['fact']);
        $this->assertStringContainsString('SIEM pricing', $rows[1]['fact']);

        // Cleanup
        unset($stmt);
        unset($collectorMock);
        unset($cognitiveMemory);
        unset($pdo);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }

    /**
     * Test QueryGraphMemoryTool searches facts correctly.
     */
    public function test_query_graph_memory_tool_returns_matching_facts(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_query_facts_'.uniqid().'.db';

        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS harness_facts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                fact TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $stmt = $pdo->prepare('INSERT INTO harness_facts (session_id, fact) VALUES (?, ?)');
        $stmt->execute(['sess-1', 'Active Directory device count is set to 150 for Client X.']);
        $stmt->execute(['sess-2', 'SIEM unit price is configured at 20.']);

        config(['harness.cache.db_path' => $dbPath]);

        $tool = new QueryGraphMemoryTool;

        // Match 1
        $res1 = $tool->execute(['query' => 'Client X']);
        $this->assertStringContainsString('Active Directory device count', $res1);
        $this->assertStringNotContainsString('SIEM unit price', $res1);

        // Match 2
        $res2 = $tool->execute(['query' => 'price']);
        $this->assertStringContainsString('SIEM unit price', $res2);
        $this->assertStringNotContainsString('Client X', $res2);

        // No match
        $res3 = $tool->execute(['query' => 'Acme']);
        $this->assertStringContainsString('No facts found matching search query', $res3);

        // Cleanup
        unset($stmt);
        unset($pdo);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }

    /**
     * Test Draft Verification Orchestration pipeline.
     */
    public function test_draft_verification_orchestration_pipelines_calls(): void
    {
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
                if (str_contains($systemPrompt, 'quick, raw draft solution')) {
                    return ['content' => 'Draft solution proposing SIEM setting update'];
                }

                return ['content' => 'Final response'];
            }

            public function getResolvedModel(): string
            {
                return 'mock';
            }
        };

        $injectorMock = new class extends OntologicalContextInjector
        {
            public function inject(
                AgentPrompt $prompt,
                string $eloquentModelClass,
                string $embeddingColumn = 'embedding',
                float $threshold = 0.30,
                int $limit = 3,
                ?array &$metadata = null
            ): AgentPrompt {
                if ($metadata !== null) {
                    $metadata = [
                        'model_class' => $eloquentModelClass,
                        'embedding_column' => $embeddingColumn,
                        'threshold' => $threshold,
                        'limit' => $limit,
                        'embedding_provider' => 'mock',
                        'evaluated_records' => [],
                        'injected_context' => "\nOntology Evidence Record: SIEM price is currently 15.",
                        'error' => null,
                    ];
                }
                // Return same prompt with appended evidence
                return $prompt->append("\nOntology Evidence Record: SIEM price is currently 15.");
            }
        };

        config(['harness.draft_verification.enabled' => true]);
        // Set invalid model class to bypass class_exists unless we mock it, wait, we mock it by using any existing class name
        config(['harness.ontology.model_class' => self::class]);

        $orchestrator = new DraftVerificationOrchestration($injectorMock);
        $result = $orchestrator->orchestrate(
            userPrompt: 'Set SIEM price to 20',
            systemPrompt: 'Base instructions',
            model: 'mock',
            client: $llmClientMock,
            sessionId: 'sess-draft-123'
        );

        $this->assertEquals(1, $llmClientMock->callCount);
        $this->assertStringContainsString('Set SIEM price to 20', $result['prompt']);
        $this->assertStringContainsString('Draft solution proposing SIEM setting update', $result['draft']);
        $this->assertStringContainsString('Ontology Evidence Record: SIEM price is currently 15', $result['prompt']);
        $this->assertStringContainsString('NEVER mention, quote, or reference the draft', $result['prompt']);
    }
}
