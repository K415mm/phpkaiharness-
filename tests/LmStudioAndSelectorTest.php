<?php

namespace Phpkaiharness\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Phpkaiharness\Core\AgentSelector;
use Phpkaiharness\Llm\LmStudioClient;
use PHPUnit\Framework\TestCase;

class LmStudioAndSelectorTest extends TestCase
{
    /**
     * Test LmStudioClient correct request formatting and parsing.
     */
    public function test_lmstudio_client_sends_correct_payload_and_parses_response(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hello from LM Studio!',
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'wsl_security_tool',
                                        'arguments' => '{"binary": "ping", "arguments": ["google.com"]}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 15,
                    'completion_tokens' => 25,
                ],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);

        $client = new class('http://localhost:1234', 'gemma-2b', $handlerStack) extends LmStudioClient
        {
            public function __construct(string $baseUrl, string $model, $handlerStack)
            {
                parent::__construct($baseUrl, $model);
                $this->httpClient = new Client(['handler' => $handlerStack]);
            }
        };

        $result = $client->chat(
            systemPrompt: 'You are an assistant',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
            ]
        );

        $this->assertEquals('Hello from LM Studio!', $result['content']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('call_abc123', $result['tool_calls'][0]['id']);
        $this->assertEquals('wsl_security_tool', $result['tool_calls'][0]['name']);
        $this->assertEquals('ping', $result['tool_calls'][0]['arguments']['binary'] ?? '');
    }

    /**
     * Test AgentSelector scans and extracts details correctly.
     */
    public function test_agent_selector_discovers_and_parses_agents(): void
    {
        // Setup a temporary directory with mock agent files
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpkaiharness_test_agents_'.uniqid();
        mkdir($tempDir, 0777, true);

        $mockAgentCode1 = <<<'PHP'
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;

#[Provider('ollama')]
#[Model('gemma-test')]
class TestMockAgent implements Agent
{
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
This is a mock instruction set.
INSTRUCTIONS;
    }
}
PHP;

        $mockAgentCode2 = <<<'PHP'
<?php

namespace App\Ai\Agents;

class TestAnotherAgent
{
    public function instructions(): string
    {
        return "Simple string instruction";
    }
}
PHP;

        file_put_contents($tempDir.DIRECTORY_SEPARATOR.'TestMockAgent.php', $mockAgentCode1);
        file_put_contents($tempDir.DIRECTORY_SEPARATOR.'TestAnotherAgent.php', $mockAgentCode2);

        $agents = AgentSelector::discover($tempDir);

        // Cleanup
        unlink($tempDir.DIRECTORY_SEPARATOR.'TestMockAgent.php');
        unlink($tempDir.DIRECTORY_SEPARATOR.'TestAnotherAgent.php');
        rmdir($tempDir);

        $this->assertCount(2, $agents);

        // Sort by name for consistency
        usort($agents, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $this->assertEquals('TestAnotherAgent', $agents[0]['name']);
        $this->assertEquals('Simple string instruction', trim($agents[0]['instructions']));

        $this->assertEquals('TestMockAgent', $agents[1]['name']);
        $this->assertEquals('App\Ai\Agents\TestMockAgent', $agents[1]['class']);
        $this->assertEquals('This is a mock instruction set.', trim($agents[1]['instructions']));
        $this->assertEquals('ollama', $agents[1]['provider']);
        $this->assertEquals('gemma-test', $agents[1]['model']);
    }
}
