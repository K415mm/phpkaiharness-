<?php

namespace Phpkaiharness\Tests;

use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use PDO;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Http\Middleware\QuantumOntologyMemoryMiddleware;
use Phpkaiharness\Jobs\AsynchronousMemoryCollapseJob;
use Phpkaiharness\Optimize\QuantumInferenceEngine;

class QuantumOntologyMemoryHarnessTest extends PhpkaiharnessTestCase
{
    private string $tempDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_quantum_harness_'.uniqid().'.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }
        parent::tearDown();
    }

    public function test_engine_initialization_schema_loading_and_wal_mode(): void
    {
        $engine = new QuantumInferenceEngine($this->tempDbPath);
        $pdo = $engine->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertTrue(file_exists($this->tempDbPath));

        // Check if tables are created
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('memory_nodes', $tables);
        $this->assertContains('memory_vectors', $tables);
        $this->assertContains('memory_edges', $tables);
        $this->assertContains('entanglement_pairs', $tables);

        // Check WAL mode
        $journalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertEquals('wal', strtolower($journalMode));
    }

    public function test_determine_phase_angle_mapping(): void
    {
        $engine = new QuantumInferenceEngine($this->tempDbPath);

        $this->assertEquals(0.0, $engine->determinePhaseAngle('SomeSecurityAgentClass'));
        $this->assertEquals(M_PI_2, $engine->determinePhaseAngle('SomeDataProcessingAgentClass'));
        $this->assertEquals(M_PI, $engine->determinePhaseAngle('SomeEpisodicAgentClass'));
        $this->assertEquals(3 * M_PI_2, $engine->determinePhaseAngle('SomeSemanticAgentClass'));
        $this->assertEquals(0.0, $engine->determinePhaseAngle('RandomAgentClass'));
    }

    public function test_cosine_similarity_calculation_fallback(): void
    {
        $engine = new QuantumInferenceEngine($this->tempDbPath);
        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('cosineSimilarity');

        $v1 = [1.0, 2.0, 3.0];
        $v2 = [1.0, 2.0, 3.0];
        $this->assertEquals(1.0, $method->invoke($engine, $v1, $v2));

        $v3 = [-1.0, -2.0, -3.0];
        $this->assertEquals(-1.0, $method->invoke($engine, $v1, $v3));

        $v4 = [0.0, 0.0, 0.0];
        $this->assertEquals(0.0, $method->invoke($engine, $v1, $v4));
    }

    public function test_synthesize_context_with_fused_score_and_entanglement(): void
    {
        config([
            'harness.quantum_harness.enabled' => true,
            'harness.quantum_harness.alpha' => 0.7,
            'harness.quantum_harness.beta' => 0.3,
            'harness.quantum_harness.similarity_threshold' => 0.3,
            'harness.quantum_harness.max_anchors' => 2,
        ]);

        $engine = new QuantumInferenceEngine($this->tempDbPath);
        $pdo = $engine->getPdo();

        // Populate test data
        $pdo->exec("INSERT INTO memory_nodes (id, type, content, phase_angle) VALUES ('node1', 'semantic', 'System core architecture details', 0.0)");
        $pdo->prepare("INSERT INTO memory_vectors (node_id, embedding) VALUES ('node1', ?)")->execute([json_encode([1.0, 0.0, 0.0])]);

        $pdo->exec("INSERT INTO memory_nodes (id, type, content, phase_angle) VALUES ('node2', 'episodic', 'Historical crash log of SIEM database', 1.0)");
        $pdo->prepare("INSERT INTO memory_vectors (node_id, embedding) VALUES ('node2', ?)")->execute([json_encode([0.0, 1.0, 0.0])]);

        $pdo->exec("INSERT INTO entanglement_pairs (node_a_id, node_b_id, entanglement_force) VALUES ('node1', 'node2', 0.8)");

        // Subclass to override getQueryEmbedding and return fixed vector
        $mockEngine = new class($this->tempDbPath) extends QuantumInferenceEngine
        {
            protected function getQueryEmbedding(string $text): array
            {
                return [1.0, 0.0, 0.0];
            }
        };

        $context = $mockEngine->synthesizeContext('architecture', 0.0);

        $this->assertStringContainsString('QUANTUM ONTOLOGY MEMORY ENVELOPE', $context);
        $this->assertStringContainsString('System core architecture details', $context);
        $this->assertStringContainsString('Historical crash log of SIEM database', $context);
        $this->assertStringContainsString('[Origin: anchor]', $context);
        $this->assertStringContainsString('[Origin: entangled]', $context);
        $this->assertStringContainsString('Score: 1.0000', $context);
        $this->assertStringContainsString('Score: 0.8000', $context);
    }

    public function test_middleware_prompt_injection(): void
    {
        config([
            'harness.quantum_harness.enabled' => true,
            'harness.quantum_harness.alpha' => 0.7,
            'harness.quantum_harness.beta' => 0.3,
            'harness.quantum_harness.similarity_threshold' => 0.3,
        ]);

        $mockEngine = new class($this->tempDbPath) extends QuantumInferenceEngine
        {
            protected function getQueryEmbedding(string $text): array
            {
                return [1.0, 0.0];
            }

            public function synthesizeContext(string $query, float $queryPhase): string
            {
                return 'Mocked retrieved context';
            }
        };

        $dummyAgent = new class implements Agent
        {
            use Promptable;

            public function instructions(): string
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
                return new StreamableAgentResponse('', new LazyCollection([]), new Meta('', ''));
            }

            public function textGateway(): TextGateway
            {
                return new class implements TextGateway
                {
                    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
                    {
                        return new TextResponse('Hello, agent response content', new Usage, new Meta('', ''));
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

        $prompt = new AgentPrompt($dummyAgent, 'Base prompt query', [], $dummyProvider, 'gemma');
        $middleware = new QuantumOntologyMemoryMiddleware($mockEngine);

        $nextCalled = false;
        $next = function (AgentPrompt $p) use (&$nextCalled) {
            $nextCalled = true;
            $this->assertStringContainsString('[QUANTUM-HARNESS MEMORY ENVELOPE]', $p->prompt);
            $this->assertStringContainsString('Mocked retrieved context', $p->prompt);

            return $p;
        };

        $result = $middleware->handle($prompt, $next);

        // Middleware is injection-only: it must forward the enriched prompt
        // unchanged (no ->then() / response handling).
        $this->assertTrue($nextCalled);
        $this->assertInstanceOf(AgentPrompt::class, $result);
        $this->assertStringContainsString('[QUANTUM-HARNESS MEMORY ENVELOPE]', $result->prompt);
    }
}

