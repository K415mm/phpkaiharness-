<?php

namespace Phpkaiharness\Tools;

use Phpkaiharness\Contracts\AgentDiscoveryInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\ToolInterface;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\Registry\ToolRegistry;

class AgentDelegationTool implements ToolInterface
{
    public function __construct(
        private readonly AgentDiscoveryInterface $discovery,
        private readonly LlmClientInterface $llmClient,
        private readonly int $maxIterations = 10
    ) {}

    public function name(): string
    {
        return 'delegate_task';
    }

    public function description(): string
    {
        return 'Delegate a specific task or sub-query to a specialized sub-agent discovered in the host project. '
            .'Use this when you need a domain expert agent to handle a request you cannot address alone.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agent_name' => [
                    'type' => 'string',
                    'description' => 'The name or fully-qualified class name of the target agent to delegate to.',
                ],
                'task' => [
                    'type' => 'string',
                    'description' => 'The specific task, query, or instruction to send to the sub-agent.',
                ],
            ],
            'required' => ['agent_name', 'task'],
        ];
    }

    /**
     * Resolve the target agent via discovery, spin up a child AgentLoop, and return its response.
     */
    public function execute(array $args): string
    {
        $agentName = $args['agent_name'] ?? '';
        $task = $args['task'] ?? '';

        if (empty($agentName) || empty($task)) {
            return json_encode(['status' => 'error', 'message' => 'agent_name and task are required.']);
        }

        $agentConfig = $this->discovery->find($agentName);

        if ($agentConfig === null) {
            return json_encode([
                'status' => 'error',
                'message' => "Sub-agent '{$agentName}' was not found by the discovery provider.",
            ]);
        }

        $systemPrompt = $agentConfig['instructions'] ?? '';
        $model = $agentConfig['model'] ?? '';

        $childLoop = new AgentLoop(
            llmClient: $this->llmClient,
            registry: new ToolRegistry,
            systemPrompt: $systemPrompt,
            model: $model,
            maxIterations: $this->maxIterations
        );

        $childLoop->setAgentName($agentConfig['name'] ?? $agentName);

        $childHistory = [];
        $result = $childLoop->run($task, $childHistory);

        return $result;
    }
}
