<?php

namespace Phpkaiharness\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\ToolInterface;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Tools\HttpServiceTool;
use Phpkaiharness\Tools\WslCommandTool;

class AgentHarnessTest extends PhpkaiharnessTestCase
{
    /**
     * Test ToolRegistry functionality.
     */
    public function test_tool_registry_attaches_and_detaches_tools(): void
    {
        $registry = new ToolRegistry;

        // Mock Tool
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'test_tool';
            }

            public function description(): string
            {
                return 'A test tool description';
            }

            public function schema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): string
            {
                return 'success';
            }
        };

        $registry->attach($tool);
        $this->assertTrue($registry->has('test_tool'));
        $this->assertSame($tool, $registry->get('test_tool'));

        $schemas = $registry->serializeSchemas();
        $this->assertCount(1, $schemas);
        $this->assertEquals('test_tool', $schemas[0]['function']['name']);

        // Detach tool
        $registry->detach('test_tool');
        $this->assertFalse($registry->has('test_tool'));
        $this->assertNull($registry->get('test_tool'));
    }

    /**
     * Test HttpServiceTool HTTP requests routing.
     */
    public function test_http_service_tool_forwards_request(): void
    {
        // Mock Guzzle HTTP client response
        $mockHandler = new MockHandler([
            new Response(200, [], '{"status": "completed", "result": "port open"}'),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);

        $tool = new class('test_http_tool', 'description', [], 'http://localhost/scan', 'token', $handlerStack) extends HttpServiceTool
        {
            public function __construct(string $name, string $desc, array $schema, string $endpoint, ?string $token, $handlerStack)
            {
                parent::__construct($name, $desc, $schema, $endpoint, $token);
                // Override internally instantiated client with our mock handler stack
                $this->httpClient = new Client(['handler' => $handlerStack]);
            }
        };

        $result = $tool->execute(['target' => '127.0.0.1']);
        $this->assertStringContainsString('port open', $result);
        $this->assertStringContainsString('completed', $result);
    }

    /**
     * Test WslCommandTool whitelisting and sanitization boundaries.
     */
    public function test_wsl_command_tool_security_enforcement(): void
    {
        $tool = new WslCommandTool('wsl_helper', 'WSL client tool', ['ping', 'nslookup']);

        // Test whitelist rejection
        $result = $tool->execute(['binary' => 'rm', 'arguments' => ['-rf', '/']]);
        $this->assertStringContainsString('Execution blocked', $result);

        // Test whitelist acceptance with mocking process exit for safe run
        $result = $tool->execute(['binary' => 'ping', 'arguments' => ['127.0.0.1', '-c', '1']]);
        $this->assertNotNull($result);

        $data = json_decode($result, true);
        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['success', 'failed']); // exit status could vary by system environment
    }

    /**
     * Test AgentLoop orchestration execution and stop conditions.
     */
    public function test_agent_loop_coordinates_multi_step_thought_actions(): void
    {
        // Create mock LLM Client
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
                if ($this->callCount === 1) {
                    // First call: Agent decides to invoke the tool
                    return [
                        'content' => 'Let me look up details.',
                        'tool_calls' => [
                            [
                                'id' => 'call_1',
                                'name' => 'lookup',
                                'arguments' => ['query' => 'testing'],
                            ],
                        ],
                    ];
                }

                // Second call: Agent processes tool result and returns final reply
                return [
                    'content' => 'I verified that it works.',
                    'tool_calls' => [],
                ];
            }

            public function getResolvedModel(): string
            {
                return 'test-model';
            }
        };

        $registry = new ToolRegistry;
        $tool = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'lookup';
            }

            public function description(): string
            {
                return 'desc';
            }

            public function schema(): array
            {
                return [];
            }

            public function execute(array $args): string
            {
                return 'result: active';
            }
        };
        $registry->attach($tool);

        $loop = new AgentLoop($llmClientMock, $registry, 'system instructions', 'test-model', 5);
        $history = [];

        $finalAnswer = $loop->run('Test query', $history);

        // Assertions
        $this->assertEquals('I verified that it works.', $finalAnswer);
        $this->assertEquals(2, $llmClientMock->callCount);

        // Verify history structure:
        // 1. User prompt
        // 2. Assistant response with tool_calls
        // 3. Tool results response
        // 4. Final Assistant response
        $this->assertCount(4, $history);
        $this->assertEquals('user', $history[0]['role']);
        $this->assertEquals('assistant', $history[1]['role']);
        $this->assertNotEmpty($history[1]['tool_calls']);
        $this->assertEquals('tool', $history[2]['role']);
        $this->assertEquals('lookup', $history[2]['name']);
        $this->assertEquals('result: active', $history[2]['content']);
        $this->assertEquals('assistant', $history[3]['role']);
    }
}
