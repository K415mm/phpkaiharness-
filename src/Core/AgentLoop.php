<?php

namespace Phpkaiharness\Core;

use Exception;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\SemanticMemoryInterface;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Core\Prompt\PromptProcessorPipeline;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Events\AgentFinished;
use Phpkaiharness\Events\AgentStarted;
use Phpkaiharness\Events\LlmCallFinished;
use Phpkaiharness\Events\LlmCallStarted;
use Phpkaiharness\Events\LlmStreamChunkReceived;
use Phpkaiharness\Events\ToolCallFinished;
use Phpkaiharness\Events\ToolCallStarted;
use Phpkaiharness\Llm\LlmClientPipelineBuilder;
use Phpkaiharness\Optimize\CognitiveGraphMemory;
use Phpkaiharness\Optimize\ComplexityClassifier;
use Phpkaiharness\Optimize\ContextCompactor;
use Phpkaiharness\Optimize\Guardrails;
use Phpkaiharness\Optimize\QuantumInferenceEngine;
use Phpkaiharness\Optimize\SemanticCache;
use Phpkaiharness\Support\HarnessConfig;
use Phpkaiharness\Tools\QueryGraphMemoryTool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AgentLoop
{
    protected ToolRegistry $registry;

    protected LlmClientInterface $llmClient;

    protected string $systemPrompt;

    protected string $model;

    protected int $maxIterations;

    protected LoggerInterface $logger;

    protected ?SemanticCache $semanticCache = null;

    protected ?ContextCompactor $contextCompactor = null;

    protected ?Guardrails $guardrails = null;

    protected string $complexityDomain = '';

    protected string $agentName = 'agent';

    /** @var array Accumulated tool calls from the current run */
    protected array $executedToolCalls = [];

    /** @var array<string> Detail event types recorded during the current run */
    protected array $recordedDetailTypes = [];

    /** @var callable|null PSR-14-compatible event dispatcher: function(object): void */
    protected $eventDispatcher = null;

    public function __construct(
        LlmClientInterface $llmClient,
        ?ToolRegistry $registry = null,
        string $systemPrompt = '',
        string $model = '',
        int $maxIterations = 10,
        ?LoggerInterface $logger = null
    ) {
        $this->llmClient = $llmClient;
        $this->registry = $registry ?? new ToolRegistry;
        $this->systemPrompt = $systemPrompt;
        $this->model = $model;
        $this->maxIterations = $maxIterations;
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Get the registry associated with this loop.
     */
    public function getRegistry(): ToolRegistry
    {
        return $this->registry;
    }

    public function setSemanticCache(?SemanticCache $cache): self
    {
        $this->semanticCache = $cache;

        return $this;
    }

    public function setContextCompactor(?ContextCompactor $compactor): self
    {
        $this->contextCompactor = $compactor;

        return $this;
    }

    public function setGuardrails(?Guardrails $guardrails): self
    {
        $this->guardrails = $guardrails;

        return $this;
    }

    /**
     * Set a human-readable agent name used in dispatched events.
     */
    public function setAgentName(string $name): self
    {
        $this->agentName = $name;

        return $this;
    }

    /**
     * Get all tool calls executed during the last run().
     *
     * @return array<int, array{name: string, arguments: array, result: string}>
     */
    public function getExecutedToolCalls(): array
    {
        return $this->executedToolCalls;
    }

    /**
     * Attach a PSR-14-compatible event dispatcher callable.
     * Signature: function(object $event): void
     */
    public function setEventDispatcher(callable $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;

        return $this;
    }

    /**
     * Dispatch an event object if a dispatcher is registered.
     */
    protected function dispatch(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            ($this->eventDispatcher)($event);
        }
    }

    /**
     * Set the system prompt for the agent run.
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    /**
     * Set the model identifier.
     */
    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Run the agent loop with a new prompt and track history.
     *
     * @param  string  $userPrompt  The latest prompt from the user.
     * @param  array  $history  Reference to the conversation history array.
     * @param  string|null  $sessionId  Session ID for analytics tracking.
     * @param  AnalyticsCollectorInterface|null  $collector  Collector instance.
     * @param  callable|null  $onChunk  Optional streaming callback: function(string $chunk): void
     * @return string Final textual output from the agent.
     */
    public function run(
        string $userPrompt,
        array &$history = [],
        ?string $sessionId = null,
        ?AnalyticsCollectorInterface $collector = null,
        ?callable $onChunk = null
    ): string {
        $this->executedToolCalls = [];
        $this->recordedDetailTypes = [];
        $this->logger->info("Starting agent execution loop for prompt: {$userPrompt}");

        // ── Sub-session tracing ────────────────────────────────────────────────
        // $sessionId is the parent/root session. Each run() gets a child interaction.
        $parentSessionId = $sessionId;
        $rootSessionId = function_exists('app') && app()->bound('harness.root_session_id')
            ? app('harness.root_session_id')
            : $sessionId;
        $requestId = uniqid('req_', true);
        $resolvedSessionId = uniqid('int_', true);

        // Determine the interaction index for this child within the parent.
        $interactionIndex = 1;
        if ($parentSessionId !== null && function_exists('app')) {
            try {
                $cacheKey = 'harness_interaction_idx_'.md5($parentSessionId);
                $interactionIndex = (int) (function_exists('cache') ? cache()->increment($cacheKey) : 1);
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to increment interaction index: '.$e->getMessage());
            }
        }

        // ── Resolve effective maxIterations from config (overrides constructor) ─
        $effectiveMaxIterations = $this->maxIterations;
        if (function_exists('config')) {
            try {
                $configMax = config('harness.default.max_iterations');
                if ($configMax !== null && (int) $configMax > 0) {
                    $effectiveMaxIterations = (int) $configMax;
                }
            } catch (\Throwable $e) {
                // keep constructor value
            }
        }

        // Bind active session ID and collector for middleware/external components
        if (function_exists('app')) {
            try {
                app()->instance('harness.active_session_id', $resolvedSessionId);
                app()->instance('harness.parent_session_id', $parentSessionId);
                app()->instance('harness.root_session_id', $rootSessionId);
                app()->instance('harness.request_id', $requestId);
                if ($collector && ! app()->bound(AnalyticsCollectorInterface::class)) {
                    app()->instance(AnalyticsCollectorInterface::class, $collector);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to bind active session id in container: '.$e->getMessage());
            }
        }

        // ── Cynefin Complexity Classification using Dirac Wave Projection ────
        $registeredToolNames = array_keys($this->registry->serializeSchemas());
        $this->complexityDomain = ComplexityClassifier::classify($userPrompt, $registeredToolNames);
        $this->logger->info('Cynefin Complexity Classification resolved to: '.strtoupper($this->complexityDomain));

        // Dynamically adjust pipeline flags based on the domain
        if (function_exists('config')) {
            try {
                if ($this->complexityDomain === ComplexityClassifier::DOMAIN_SIMPLE) {
                    // Simple Domain: Direct LLM execution, disable RAG, loops, cache
                    config(['harness.feature_graph.nodes.semantic_cache.enabled' => false]);
                    config(['harness.feature_graph.nodes.ontology_injection.enabled' => false]);
                    config(['harness.feature_graph.nodes.cognitive_memory.enabled' => false]);
                    config(['harness.feature_graph.nodes.quantum_harness.enabled' => false]);
                    config(['harness.feature_graph.nodes.draft_verification.enabled' => false]);

                    config(['harness.cache.enabled' => false]);
                    config(['harness.ontology.enabled' => false]);
                    config(['harness.cognitive_memory.enabled' => false]);
                    config(['harness.quantum_harness.enabled' => false]);
                    config(['harness.draft_verification.enabled' => false]);
                } elseif ($this->complexityDomain === ComplexityClassifier::DOMAIN_COMPLICATED) {
                    // Complicated Domain: Enable Ontology/RAG, disable tool loops
                    config(['harness.feature_graph.nodes.ontology_injection.enabled' => true]);
                    config(['harness.feature_graph.nodes.semantic_cache.enabled' => false]);
                    config(['harness.feature_graph.nodes.cognitive_memory.enabled' => false]);
                    config(['harness.feature_graph.nodes.quantum_harness.enabled' => false]);
                    config(['harness.feature_graph.nodes.draft_verification.enabled' => false]);

                    config(['harness.ontology.enabled' => true]);
                    config(['harness.cache.enabled' => false]);
                    config(['harness.cognitive_memory.enabled' => false]);
                    config(['harness.quantum_harness.enabled' => false]);
                    config(['harness.draft_verification.enabled' => false]);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to dynamically route pipeline config: '.$e->getMessage());
            }
        }

        // ── Auto-configure features from host application harness.php config ──
        if (function_exists('config')) {
            try {
                // 1. Auto-configure Semantic Cache (check feature_graph first, then legacy)
                $cacheEnabled = config('harness.feature_graph.nodes.semantic_cache.enabled', config('harness.cache.enabled', config('harness.semantic_cache.enabled', false)));
                if ($cacheEnabled) {
                    if ($this->semanticCache === null) {
                        $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
                        $semanticMemory = null;
                        if (function_exists('app') && app()->bound(SemanticMemoryInterface::class)) {
                            $semanticMemory = app(SemanticMemoryInterface::class);
                        }
                        $this->semanticCache = new SemanticCache(
                            pdo: new \PDO('sqlite:'.$dbPath),
                            threshold: (float) config('harness.cache.threshold', config('harness.semantic_cache.threshold', 0.88)),
                            semanticMemory: $semanticMemory
                        );
                    }
                } else {
                    // Teardown: disable cache if it was previously enabled but now turned off
                    $this->semanticCache = null;
                }

                // 2. Auto-configure Context Compactor (check feature_graph first)
                $compactionEnabled = config('harness.feature_graph.nodes.context_compactor.enabled', config('harness.compaction.enabled', config('harness.context_compactor.enabled', true)));
                if ($compactionEnabled) {
                    if ($this->contextCompactor === null) {
                        $this->contextCompactor = new ContextCompactor(
                            strategy: (string) config('harness.compaction.strategy', config('harness.context_compactor.strategy', 'sliding_window')),
                            maxTurns: (int) config('harness.compaction.max_turns', config('harness.context_compactor.max_turns', config('harness.context_compactor.window_size', 6))),
                            maxTokensThreshold: (int) config('harness.compaction.max_tokens_threshold', config('harness.context_compactor.max_tokens_threshold', 4000))
                        );
                    }
                } else {
                    $this->contextCompactor = null;
                }

                // 3. Auto-configure Guardrails (check feature_graph first)
                $guardrailsEnabled = config('harness.feature_graph.nodes.guardrails.enabled', config('harness.guardrails.enabled', false));
                if ($guardrailsEnabled) {
                    if ($this->guardrails === null) {
                        $this->guardrails = new Guardrails;
                    }
                } else {
                    $this->guardrails = null;
                }

                // Auto-configure QueryGraphMemoryTool (check feature_graph first)
                $cognitiveMemoryEnabled = config('harness.feature_graph.nodes.cognitive_memory.enabled', config('harness.cognitive_memory.enabled', config('harness.cognitive_graph_memory.enabled', false)));
                if ($cognitiveMemoryEnabled) {
                    if (! $this->registry->has('query_graph_memory')) {
                        $this->registry->attach(new QueryGraphMemoryTool);
                    }
                } else {
                    if ($this->registry->has('query_graph_memory')) {
                        $this->registry->remove('query_graph_memory');
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to auto-configure harness features: '.$e->getMessage());
            }
        }

        // ── Record resolved feature matrix ───────────────────────────────────
        if ($collector && $resolvedSessionId && function_exists('config')) {
            try {
                $featureMatrix = [];
                $features = [
                    'semantic_cache', 'context_compactor', 'guardrails', 'cognitive_memory',
                    'quantum_harness', 'draft_verification', 'model_optimizer', 'ontology_injection',
                    'environment_bootstrap', 'context_compression',
                ];
                foreach ($features as $feature) {
                    $featureMatrix[$feature] = HarnessConfig::isNodeEnabled($feature, null, false);
                }
                $featureMatrix['failover'] = (bool) config('harness.failover.enabled', false);
                $featureMatrix['pii_masking'] = (bool) config('harness.pii_masking.enabled', false);
                $featureMatrix['rate_limiting'] = (bool) config('harness.rate_limiting.enabled', false);
                $featureMatrix['budget'] = (bool) config('harness.budget.enabled', false);
                $featureMatrix['policy_guardrail'] = (bool) config('harness.policy_guardrail.enabled', false);

                $collector->recordEvent(
                    $resolvedSessionId,
                    'feature_matrix',
                    'ResolvedFeatureMatrix',
                    ['features' => $featureMatrix, 'model' => $this->model, 'agent' => $this->agentName, 'complexity_domain' => $this->complexityDomain],
                    json_encode(['status' => 'Feature matrix resolved at run start. Complexity: '.$this->complexityDomain], JSON_UNESCAPED_UNICODE) ?: '{}'
                );
                $this->recordedDetailTypes[] = 'feature_matrix';
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to record feature matrix: '.$e->getMessage());
            }
        }

        // ── Smart model resolution ────────────────────────────────────────────
        $effectiveModel = $this->model;

        if (empty($effectiveModel)) {
            $effectiveModel = $this->llmClient->getResolvedModel();
        }

        if (empty($effectiveModel) && function_exists('config')) {
            try {
                $effectiveModel = (string) config('harness.default.model', '');
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to check config default model: '.$e->getMessage());
            }
        }

        // Sync the resolved model back
        if (! empty($effectiveModel) && $effectiveModel !== $this->model) {
            $this->model = $effectiveModel;
            $this->logger->info("phpkaiharness auto-detected runtime model: {$effectiveModel}");
        }

        // ── Prompt Processing Pipeline ────────────────────────────────────────
        // Runs draft-verification, prompt middleware, optimizer, and ontology
        // injection stages in sequence via PromptProcessorPipeline.
        $promptContext = new PromptContext(
            userPrompt: $userPrompt,
            systemPrompt: $this->systemPrompt,
            effectiveModel: $effectiveModel,
            sessionId: $resolvedSessionId,
            collector: $collector,
            llmClient: $this->llmClient,
            agentName: $this->agentName,
            logger: $this->logger
        );

        $promptPipeline = new PromptProcessorPipeline;
        $promptContext = $promptPipeline->run($promptContext);

        $effectiveUserPrompt = $promptContext->effectiveUserPrompt;
        $optimizedSystemPrompt = $promptContext->optimizedSystemPrompt;
        $optimizedUserPrompt = $promptContext->effectiveUserPrompt;

        $loopStartTime = microtime(true);

        $this->dispatch(new AgentStarted($resolvedSessionId, $this->agentName, $optimizedUserPrompt, $optimizedSystemPrompt, $this->model));

        // ── Semantic Cache Lookup ─────────────────────────────────────────────
        if ($this->semanticCache) {
            $cachedResponse = $this->semanticCache->lookup($userPrompt);
            if ($cachedResponse !== null) {
                $this->logger->info("Semantic cache hit for prompt: {$userPrompt}");
                if ($collector && $resolvedSessionId) {
                    $collector->recordEvent(
                        $resolvedSessionId,
                        'cache',
                        'hit',
                        ['prompt' => $userPrompt],
                        json_encode(['result' => $cachedResponse], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                    $this->recordedDetailTypes[] = 'cache';
                    $collector->startSession(
                        $resolvedSessionId,
                        $userPrompt,
                        'semantic-cache-hit',
                        $parentSessionId,
                        $interactionIndex,
                        $rootSessionId,
                        $requestId,
                        'interaction'
                    );
                    $collector->endSession($resolvedSessionId, $cachedResponse, 0, 0);
                }
                $history[] = ['role' => 'user', 'content' => $optimizedUserPrompt];
                $history[] = ['role' => 'assistant', 'content' => $cachedResponse];

                if ($onChunk !== null) {
                    $onChunk($cachedResponse);
                }

                $this->dispatch(new AgentFinished($resolvedSessionId, $this->agentName, $cachedResponse, 0, 0));

                return $cachedResponse;
            } else {
                if ($collector && $resolvedSessionId) {
                    $collector->recordEvent(
                        $resolvedSessionId,
                        'cache',
                        'miss',
                        ['prompt' => $userPrompt],
                        json_encode(['result' => 'Cache miss: executing agent loop'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                    $this->recordedDetailTypes[] = 'cache';
                }
            }
        }

        $history[] = [
            'role' => 'user',
            'content' => $optimizedUserPrompt,
        ];

        if ($collector && $resolvedSessionId) {
            $collector->startSession(
                $resolvedSessionId,
                $userPrompt,
                'executor-loop',
                $parentSessionId,
                $interactionIndex,
                $rootSessionId,
                $requestId,
                'interaction'
            );
            $collector->recordEvent(
                $resolvedSessionId,
                'bootstrap',
                'AgentLoop',
                [
                    'agent' => $this->agentName,
                    'model' => $this->model,
                    'parent_session_id' => $parentSessionId,
                    'root_session_id' => $rootSessionId,
                    'request_id' => $requestId,
                    'interaction_index' => $interactionIndex,
                ],
                json_encode(['status' => 'started'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
            );
            $this->recordedDetailTypes[] = 'bootstrap';
        }

        // Auto-decorate client with failover, rate limiting, and PII masking
        $client = $this->decorateLlmClient($this->llmClient);

        $iteration = 0;
        for ($iteration = 0; $iteration < $effectiveMaxIterations; $iteration++) {
            $this->logger->debug("Agent loop iteration {$iteration} of {$effectiveMaxIterations}");

            // ── Context Compactor ─────────────────────────────────────────────
            if ($this->contextCompactor) {
                $originalTurns = count($history);
                $this->contextCompactor->compact($history, $optimizedSystemPrompt, $this->model, $client);
                $compactedTurns = count($history);
                if ($collector && $resolvedSessionId) {
                    $collector->recordEvent(
                        $resolvedSessionId,
                        'compaction',
                        $this->contextCompactor->getStrategy(),
                        ['turns_before' => $originalTurns],
                        json_encode([
                            'turns_after' => $compactedTurns,
                            'status' => $originalTurns === $compactedTurns ? 'No compaction needed: conversation size within limits.' : 'Compacted successfully.',
                        ], JSON_UNESCAPED_UNICODE)
                    );
                    $this->recordedDetailTypes[] = 'compaction';
                }
            }

            $toolSchemas = $this->registry->serializeSchemas();

            $this->dispatch(new LlmCallStarted($resolvedSessionId, $this->agentName, $iteration, $this->model, $history, $toolSchemas));

            try {
                $llmStartTime = microtime(true);

                $streamCallback = $onChunk;
                if ($this->eventDispatcher !== null) {
                    $dispatcher = $this->eventDispatcher;
                    $capturedIteration = $iteration;
                    $capturedSession = $resolvedSessionId;
                    $capturedAgent = $this->agentName;
                    $streamCallback = function (string $chunk) use ($onChunk, $dispatcher, $capturedIteration, $capturedSession, $capturedAgent): void {
                        $dispatcher(new LlmStreamChunkReceived($capturedSession, $capturedAgent, $capturedIteration, $chunk));
                        if ($onChunk !== null) {
                            $onChunk($chunk);
                        }
                    };
                }

                // Invoke decorated LLM client
                $response = $client->chat(
                    $optimizedSystemPrompt,
                    $history,
                    $toolSchemas,
                    $this->model,
                    $resolvedSessionId,
                    $collector,
                    $streamCallback
                );

                $llmDurationMs = (int) ((microtime(true) - $llmStartTime) * 1000);
                $this->recordedDetailTypes[] = 'llm_call';
                $this->dispatch(new LlmCallFinished(
                    $resolvedSessionId,
                    $this->agentName,
                    $iteration,
                    $this->model,
                    $response['content'] ?? null,
                    $response['tool_calls'] ?? [],
                    $llmDurationMs,
                    ['prompt_tokens' => 0, 'completion_tokens' => 0]
                ));
            } catch (Exception $e) {
                $this->logger->error('LLM Chat failed: '.$e->getMessage());

                $errResponse = '⚠️ LLM execution error: '.$e->getMessage();
                if ($collector && $resolvedSessionId) {
                    $duration = (int) ((microtime(true) - $loopStartTime) * 1000);
                    $collector->recordEvent(
                        $resolvedSessionId,
                        'llm_call',
                        'error',
                        ['iteration' => $iteration],
                        json_encode(['status' => 'failed', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                        $duration
                    );
                    $this->recordedDetailTypes[] = 'llm_call';
                    $collector->endSession($resolvedSessionId, $errResponse, $duration, $iteration);
                }

                $this->dispatch(new AgentFinished($resolvedSessionId, $this->agentName, $errResponse, (int) ((microtime(true) - $loopStartTime) * 1000), $iteration));

                return $errResponse;
            }

            $toolCalls = $response['tool_calls'] ?? [];
            $content = $response['content'] ?? null;

            if (empty($toolCalls)) {
                $finalText = $content ?? '';
                $history[] = ['role' => 'assistant', 'content' => $finalText];
                $this->logger->info('Agent loop completed successfully with final response.');

                if ($collector && $resolvedSessionId) {
                    $duration = (int) ((microtime(true) - $loopStartTime) * 1000);
                    $collector->endSession($resolvedSessionId, $finalText, $duration, $iteration + 1);
                }

                // ── Quantum Memory Ingestion ───────────────────────────────────
                $this->ingestQuantumMemory($resolvedSessionId, $userPrompt, $finalText, $collector);

                // ── Cognitive Memory Extraction ────────────────────────────────
                $this->extractCognitiveMemory($resolvedSessionId, $userPrompt, $finalText, $collector);

                // ── Record skipped features ────────────────────────────────────
                $this->recordSkippedFeatures($resolvedSessionId, $collector);

                $duration = (int) ((microtime(true) - $loopStartTime) * 1000);
                $this->dispatch(new AgentFinished($resolvedSessionId, $this->agentName, $finalText, $duration, $iteration + 1));

                return $finalText;
            }

            $history[] = [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $callId = $call['id'] ?? uniqid('call_');
                $toolName = $call['name'] ?? '';
                $arguments = $call['arguments'] ?? [];

                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?? [];
                }

                // ── Guardrails Tool Validation ───────────────────────────────
                if ($this->guardrails) {
                    $guardResult = $this->guardrails->validate($toolName, $arguments);
                    if ($guardResult !== true) {
                        $this->logger->warning("Guardrails blocked tool execution: {$toolName}. Reason: {$guardResult}");
                        $toolResult = json_encode([
                            'status' => 'blocked',
                            'message' => $guardResult,
                        ]);

                        if ($collector && $resolvedSessionId) {
                            $collector->recordEvent(
                                $resolvedSessionId,
                                'guardrail',
                                $toolName,
                                ['arguments' => $arguments],
                                json_encode(['decision' => 'Blocked: '.$guardResult], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                            );
                            $collector->recordToolCall($resolvedSessionId, $toolName, $arguments, $toolResult, 0);
                            $this->recordedDetailTypes[] = 'guardrail';
                            $this->recordedDetailTypes[] = 'tool_call';
                        }

                        $history[] = [
                            'role' => 'tool',
                            'tool_call_id' => $callId,
                            'name' => $toolName,
                            'content' => $toolResult,
                        ];

                        continue;
                    } else {
                        if ($collector && $resolvedSessionId) {
                            $collector->recordEvent(
                                $resolvedSessionId,
                                'guardrail',
                                $toolName,
                                ['arguments' => $arguments],
                                json_encode(['decision' => 'Allowed'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                            );
                            $this->recordedDetailTypes[] = 'guardrail';
                        }
                    }
                }

                $this->logger->info("Executing tool: {$toolName}", ['args' => $arguments]);

                $this->dispatch(new ToolCallStarted($resolvedSessionId, $this->agentName, $callId, $toolName, $arguments));

                $toolStartTime = microtime(true);
                if ($this->registry->has($toolName)) {
                    try {
                        $toolResult = $this->registry->get($toolName)->execute($arguments);
                    } catch (Exception $e) {
                        $this->logger->warning("Tool {$toolName} thrown an exception: ".$e->getMessage());
                        $toolResult = json_encode([
                            'status' => 'error',
                            'message' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $this->logger->warning("Tool {$toolName} not found in registry.");
                    $toolResult = json_encode([
                        'status' => 'error',
                        'message' => "Tool '{$toolName}' not found in registry.",
                    ]);
                }
                $toolDuration = (int) ((microtime(true) - $toolStartTime) * 1000);

                $this->dispatch(new ToolCallFinished($resolvedSessionId, $this->agentName, $callId, $toolName, $arguments, $toolResult, $toolDuration));

                $this->executedToolCalls[] = [
                    'name' => $toolName,
                    'arguments' => $arguments,
                    'result' => $toolResult,
                ];

                if ($collector && $resolvedSessionId) {
                    $collector->recordToolCall($resolvedSessionId, $toolName, $arguments, $toolResult, $toolDuration);
                    $this->recordedDetailTypes[] = 'tool_call';
                }

                $history[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'name' => $toolName,
                    'content' => $toolResult,
                ];

                // ── Cache Invalidation on Mutating Tools ───────────────────────
                if ($this->semanticCache && $this->isMutatingTool($toolName)) {
                    $invalidated = $this->semanticCache->invalidate($userPrompt);
                    if ($invalidated > 0 && $collector && $resolvedSessionId) {
                        $collector->recordEvent(
                            $resolvedSessionId,
                            'cache',
                            'invalidated',
                            ['tool' => $toolName, 'invalidated_count' => $invalidated],
                            json_encode(['status' => 'Cache entries invalidated after mutating tool execution'], JSON_UNESCAPED_UNICODE) ?: '{}'
                        );
                    }
                }
            }
        }

        $this->logger->warning("Agent loop reached maximum iterations limit ({$effectiveMaxIterations})");

        $limitMessage = '⚠️ Agent loop iteration limit reached. Last state was suspended.';
        $finalDuration = (int) ((microtime(true) - $loopStartTime) * 1000);
        if ($collector && $resolvedSessionId) {
            $this->ingestQuantumMemory($resolvedSessionId, $userPrompt, $limitMessage, $collector);
            $this->extractCognitiveMemory($resolvedSessionId, $userPrompt, $limitMessage, $collector);
            $this->recordSkippedFeatures($resolvedSessionId, $collector);
            $collector->endSession($resolvedSessionId, $limitMessage, $finalDuration, $effectiveMaxIterations);
        }

        $this->dispatch(new AgentFinished($resolvedSessionId, $this->agentName, $limitMessage, $finalDuration, $effectiveMaxIterations));

        return $limitMessage;
    }

    /**
     * Record real "skipped" detail rows for enabled features that did not
     * execute during this interaction, so the dashboard shows real data
     * instead of synthetic "missing telemetry" nodes.
     */
    protected function recordSkippedFeatures(string $sessionId, ?AnalyticsCollectorInterface $collector): void
    {
        if (! $collector || ! function_exists('config')) {
            return;
        }

        $skipMap = [
            'draft_verification' => fn () => config('harness.feature_graph.nodes.draft_verification.enabled', config('harness.draft_verification.enabled', false)),
            'ontology' => fn () => config('harness.feature_graph.nodes.ontology_injection.enabled', config('harness.ontology.enabled', config('harness.ontological_injector.enabled', false))),
            'optimizer' => fn () => config('harness.feature_graph.nodes.model_optimizer.enabled', config('harness.optimizer.enabled', config('harness.model_prompt_optimizer.enabled', false))),
            'pii_masking' => fn () => config('harness.pii_masking.enabled', false),
            'cache' => fn () => config('harness.feature_graph.nodes.semantic_cache.enabled', config('harness.cache.enabled', config('harness.semantic_cache.enabled', false))),
            'rate_limit' => fn () => config('harness.rate_limiting.enabled', false),
            'policy_guardrail' => fn () => config('harness.policy_guardrail.enabled', false),
            'guardrail' => fn () => config('harness.feature_graph.nodes.guardrails.enabled', config('harness.guardrails.enabled', false)),
            'compaction' => fn () => config('harness.feature_graph.nodes.context_compactor.enabled', config('harness.compaction.enabled', config('harness.context_compactor.enabled', false))),
            'compression' => fn () => config('harness.feature_graph.nodes.context_compression.enabled', config('harness.compaction.compression.enabled', false)),
            'cognitive_memory' => fn () => config('harness.feature_graph.nodes.cognitive_memory.enabled', config('harness.cognitive_memory.enabled', config('harness.cognitive_graph_memory.enabled', false))),
            'budget' => fn () => config('harness.budget.enabled', false),
            'failover' => fn () => config('harness.failover.enabled', false),
            'quantum_collapse' => fn () => config('harness.quantum_harness.enabled', false),
        ];

        $executedTypes = $this->recordedDetailTypes;
        foreach ($skipMap as $type => $checker) {
            try {
                if (! in_array($type, $executedTypes) && $checker()) {
                    $collector->recordEvent(
                        $sessionId,
                        $type,
                        'skipped',
                        ['reason' => 'enabled_but_not_triggered'],
                        json_encode(['status' => 'skipped', 'message' => 'Feature was enabled but did not trigger during this interaction.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->debug("Failed to record skipped event for {$type}: ".$e->getMessage());
            }
        }
    }

    /**
     * Ingest the completed interaction into quantum memory as episodic nodes.
     *
     * Stores the user's prompt and the assistant's response as memory nodes
     * with embeddings, so subsequent interactions can retrieve them via
     * quantum similarity search.
     */
    protected function ingestQuantumMemory(?string $sessionId, string $prompt, string $response, ?AnalyticsCollectorInterface $collector): void
    {
        if (! function_exists('config') || ! config('harness.quantum_harness.enabled', false)) {
            return;
        }

        try {
            $engine = function_exists('app') && app()->bound(QuantumInferenceEngine::class)
                ? app(QuantumInferenceEngine::class)
                : new QuantumInferenceEngine;

            $phaseAngle = $engine->determinePhaseAngle($this->agentName);
            $nodeBase = $sessionId ? $sessionId.'_' : 'qmem_';
            $timestamp = time();

            // Store the user prompt as an episodic memory node
            $promptId = $nodeBase.'prompt_'.$timestamp;
            $storedPrompt = $engine->storeNode($promptId, 'episodic', $prompt, $phaseAngle);

            // Store the assistant response as a semantic memory node
            $responseId = $nodeBase.'response_'.$timestamp;
            $storedResponse = $engine->storeNode($responseId, 'semantic', $response, $phaseAngle);

            if ($collector && $sessionId && ($storedPrompt || $storedResponse)) {
                $collector->recordEvent(
                    $sessionId,
                    'quantum_ingest',
                    'QuantumMemoryIngestion',
                    ['prompt_node' => $promptId, 'response_node' => $responseId, 'stored' => true],
                    json_encode(['status' => 'Ingested '.((int) $storedPrompt + (int) $storedResponse).' memory nodes'], JSON_UNESCAPED_UNICODE) ?: '{}'
                );
                $this->recordedDetailTypes[] = 'quantum_ingest';
            }

            $this->logger->info("Quantum memory ingestion: stored prompt and response nodes for session {$sessionId}");
        } catch (\Throwable $e) {
            $this->logger->warning('Quantum memory ingestion failed: '.$e->getMessage());
        }
    }

    /**
     * Extract cognitive facts from the interaction and store them in the
     * persistent cognitive graph memory for future agent queries.
     */
    protected function extractCognitiveMemory(?string $sessionId, string $prompt, string $response, ?AnalyticsCollectorInterface $collector): void
    {
        if (! function_exists('config') || ! config('harness.feature_graph.nodes.cognitive_memory.enabled', config('harness.cognitive_memory.enabled', config('harness.cognitive_graph_memory.enabled', false)))) {
            return;
        }

        if (! $collector || ! $sessionId) {
            return;
        }

        try {
            $extractor = new CognitiveGraphMemory;
            $extractor->extractAndStore($sessionId, $prompt, $response, $this->llmClient, $collector);
            $this->recordedDetailTypes[] = 'cognitive_memory';
            $this->logger->info("Cognitive memory extraction completed for session {$sessionId}");
        } catch (\Throwable $e) {
            $this->logger->warning('Cognitive memory extraction failed: '.$e->getMessage());
            if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                info('Cognitive memory extraction failed in AgentLoop: '.$e->getMessage());
            }
        }
    }

    /**
     * Wrap the base client with rate limiting, PII masking, and failover decorators based on config.
     */
    protected function decorateLlmClient(LlmClientInterface $client): LlmClientInterface
    {
        if (! function_exists('config')) {
            return $client;
        }

        if (! config('harness.auto_decorate', true)) {
            return $client;
        }

        try {
            $builder = new LlmClientPipelineBuilder;
            $builder->fromConfig(config('harness', []));

            return $builder->build($client);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to decorate LLM client: '.$e->getMessage());

            return $client;
        }
    }

    /**
     * Check if a tool is likely to mutate state and should trigger cache invalidation.
     */
    protected function isMutatingTool(string $toolName): bool
    {
        $mutatingPatterns = [
            'create_', 'add_', 'update_', 'delete_', 'remove_', 'set_',
            'save_', 'store_', 'insert_', 'modify_', 'assign_', 'allocate_',
            'wsl_command', 'execute_',
        ];

        $lower = strtolower($toolName);
        foreach ($mutatingPatterns as $pattern) {
            if (str_starts_with($lower, $pattern) || $lower === $pattern) {
                return true;
            }
        }

        return false;
    }
}
