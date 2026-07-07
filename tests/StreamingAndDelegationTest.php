<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Contracts\AgentDiscoveryInterface;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\Event\AgentFinishedInterface;
use Phpkaiharness\Contracts\Event\AgentStartedInterface;
use Phpkaiharness\Contracts\Event\LlmCallFinishedInterface;
use Phpkaiharness\Contracts\Event\LlmCallStartedInterface;
use Phpkaiharness\Contracts\Event\LlmStreamChunkReceivedInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\ToolInterface;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Tools\AgentDelegationTool;

class StreamingAndDelegationTest extends PhpkaiharnessTestCase
{
    /**
     * Test that $onChunk callback is invoked with the LLM response text when no tool calls are made.
     */
    public function test_streaming_callback_invoked_with_response_chunks(): void
    {
        $llmMock = new class implements LlmClientInterface
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
                if ($onChunk !== null) {
                    $onChunk('Hello ');
                    $onChunk('world!');
                }

                return ['content' => 'Hello world!', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $loop = new AgentLoop($llmMock, new ToolRegistry, 'system', 'test-model', 5);

        $chunks = [];
        $history = [];
        $result = $loop->run('Hi', $history, null, null, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $this->assertEquals('Hello world!', $result);
        $this->assertCount(2, $chunks);
        $this->assertEquals('Hello ', $chunks[0]);
        $this->assertEquals('world!', $chunks[1]);
    }

    /**
     * Test that all PSR-14 lifecycle events are dispatched in correct order.
     */
    public function test_event_dispatcher_fires_lifecycle_events(): void
    {
        $llmMock = new class implements LlmClientInterface
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
                    return [
                        'content' => null,
                        'tool_calls' => [
                            ['id' => 'call_1', 'name' => 'ping', 'arguments' => ['msg' => 'test']],
                        ],
                    ];
                }

                return ['content' => 'Done!', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $registry = new ToolRegistry;
        $registry->attach(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'ping';
            }

            public function description(): string
            {
                return 'Ping tool';
            }

            public function schema(): array
            {
                return [];
            }

            public function execute(array $args): string
            {
                return 'pong';
            }
        });

        $loop = new AgentLoop($llmMock, $registry, 'system', 'test-model', 5);
        $loop->setAgentName('TestAgent');

        $dispatchedEvents = [];
        $loop->setEventDispatcher(function (object $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = get_class($event);
        });

        $history = [];
        $result = $loop->run('Run test', $history, 'session-123');

        $this->assertEquals('Done!', $result);

        $this->assertStringContainsString('AgentStarted', $dispatchedEvents[0]);
        $this->assertStringContainsString('LlmCallStarted', $dispatchedEvents[1]);
        $this->assertStringContainsString('LlmCallFinished', $dispatchedEvents[2]);
        $this->assertStringContainsString('ToolCallStarted', $dispatchedEvents[3]);
        $this->assertStringContainsString('ToolCallFinished', $dispatchedEvents[4]);
        $this->assertStringContainsString('LlmCallStarted', $dispatchedEvents[5]);
        $this->assertStringContainsString('LlmCallFinished', $dispatchedEvents[6]);
        $this->assertStringContainsString('AgentFinished', $dispatchedEvents[7]);
    }

    /**
     * Test that event objects implement the correct interfaces.
     */
    public function test_dispatched_events_implement_correct_interfaces(): void
    {
        $llmMock = new class implements LlmClientInterface
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
                return ['content' => 'Response', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $loop = new AgentLoop($llmMock, new ToolRegistry, 'system', 'gpt-4', 5);
        $loop->setAgentName('InterfaceAgent');

        $events = [];
        $loop->setEventDispatcher(function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $history = [];
        $loop->run('Test', $history, 'sess-abc');

        $this->assertInstanceOf(AgentStartedInterface::class, $events[0]);
        $this->assertInstanceOf(LlmCallStartedInterface::class, $events[1]);
        $this->assertInstanceOf(LlmCallFinishedInterface::class, $events[2]);
        $this->assertInstanceOf(AgentFinishedInterface::class, $events[3]);

        $started = $events[0];
        $this->assertStringStartsWith('int_', $started->getSessionId());
        $this->assertEquals('InterfaceAgent', $started->getAgentName());
        $this->assertEquals('Test', $started->getPrompt());
        $this->assertEquals('gpt-4', $started->getModel());

        $finished = $events[3];
        $this->assertEquals('Response', $finished->getFinalResponse());
        $this->assertEquals(1, $finished->getIterations());
    }

    /**
     * Test that LlmStreamChunkReceived events are dispatched for each chunk when $onChunk is active.
     */
    public function test_stream_chunk_events_fired_per_chunk(): void
    {
        $llmMock = new class implements LlmClientInterface
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
                if ($onChunk !== null) {
                    $onChunk('chunk1');
                    $onChunk('chunk2');
                    $onChunk('chunk3');
                }

                return ['content' => 'chunk1chunk2chunk3', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $loop = new AgentLoop($llmMock, new ToolRegistry, 'system', 'model', 5);
        $loop->setAgentName('StreamAgent');

        $chunkEvents = [];
        $loop->setEventDispatcher(function (object $event) use (&$chunkEvents): void {
            if ($event instanceof LlmStreamChunkReceivedInterface) {
                $chunkEvents[] = $event->getChunk();
            }
        });

        $history = [];
        $loop->run('Stream test', $history, null, null, function (string $chunk): void {});

        $this->assertCount(3, $chunkEvents);
        $this->assertEquals('chunk1', $chunkEvents[0]);
        $this->assertEquals('chunk2', $chunkEvents[1]);
        $this->assertEquals('chunk3', $chunkEvents[2]);
    }

    /**
     * Test that AgentDelegationTool correctly resolves and delegates to a sub-agent loop.
     */
    public function test_agent_delegation_tool_runs_sub_agent(): void
    {
        $discovery = new class implements AgentDiscoveryInterface
        {
            public function discover(): array
            {
                return [
                    'AnalystAgent' => [
                        'name' => 'AnalystAgent',
                        'class' => 'App\\Ai\\Agents\\AnalystAgent',
                        'instructions' => 'You are a financial analyst.',
                        'provider' => 'ollama',
                        'model' => 'gemma4',
                        'tools' => [],
                    ],
                ];
            }

            public function find(string $agentName): ?array
            {
                return $this->discover()[$agentName] ?? null;
            }
        };

        $llmMock = new class implements LlmClientInterface
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
                return ['content' => 'Margin is 24%.', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $tool = new AgentDelegationTool($discovery, $llmMock);

        $this->assertEquals('delegate_task', $tool->name());
        $this->assertNotEmpty($tool->description());

        $schema = $tool->schema();
        $this->assertArrayHasKey('agent_name', $schema['properties']);
        $this->assertArrayHasKey('task', $schema['properties']);

        $result = $tool->execute(['agent_name' => 'AnalystAgent', 'task' => 'What is the margin?']);
        $this->assertEquals('Margin is 24%.', $result);
    }

    /**
     * Test that AgentDelegationTool returns an error when agent is not found.
     */
    public function test_agent_delegation_returns_error_for_unknown_agent(): void
    {
        $discovery = new class implements AgentDiscoveryInterface
        {
            public function discover(): array
            {
                return [];
            }

            public function find(string $agentName): ?array
            {
                return null;
            }
        };

        $llmMock = new class implements LlmClientInterface
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
                return ['content' => '', 'tool_calls' => []];
            }

            public function getResolvedModel(): string
            {
                return '';
            }
        };

        $tool = new AgentDelegationTool($discovery, $llmMock);
        $result = $tool->execute(['agent_name' => 'NonExistentAgent', 'task' => 'Do something']);

        $decoded = json_decode($result, true);
        $this->assertEquals('error', $decoded['status']);
        $this->assertStringContainsString('NonExistentAgent', $decoded['message']);
    }
}
