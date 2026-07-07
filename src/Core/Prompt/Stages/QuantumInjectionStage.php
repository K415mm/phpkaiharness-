<?php

namespace Phpkaiharness\Core\Prompt\Stages;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\DummyTextProvider;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Http\Middleware\QuantumOntologyMemoryMiddleware;
use Phpkaiharness\Optimize\QuantumInferenceEngine;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Quantum Injection stage: independently injects quantum-inspired ontology
 * memory into the prompt. Runs regardless of prompt_middleware feature_graph
 * node, controlled solely by harness.quantum_harness.enabled.
 */
class QuantumInjectionStage implements PromptProcessorInterface
{
    public function isEnabled(PromptContext $context): bool
    {
        if (isset($context->philosophyFlags['use_quantum']) && ! $context->philosophyFlags['use_quantum']) {
            return false;
        }

        return HarnessConfig::isNodeEnabled('quantum_harness', 'harness.quantum_harness.enabled', false);
    }

    public function process(PromptContext $context): PromptContext
    {
        $dummyProvider = new DummyTextProvider;

        try {
            $engine = function_exists('app') && app()->bound(QuantumInferenceEngine::class)
                ? app(QuantumInferenceEngine::class)
                : new QuantumInferenceEngine;

            // Early exit: skip everything if memory DB is empty
            $pdo = $engine->getPdo();
            $nodeCount = (int) $pdo->query('SELECT COUNT(*) FROM memory_nodes')->fetchColumn();
            if ($nodeCount === 0) {
                $context->logger?->debug('QuantumInjectionStage: memory DB empty, skipping.');

                if ($context->collector && $context->sessionId) {
                    $context->collector->recordEvent(
                        $context->sessionId,
                        'quantum',
                        'quantum_harness',
                        ['enabled' => true, 'injected' => false],
                        json_encode(['status' => 'Memory DB empty — no nodes to inject'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                }

                return $context;
            }

            $laravelPrompt = new AgentPrompt(
                agent: class_exists($context->agentName) ? new $context->agentName : new AnonymousAgent('', [], []),
                prompt: $context->effectiveUserPrompt,
                attachments: [],
                provider: $dummyProvider,
                model: $context->effectiveModel
            );

            $quantumMiddleware = new QuantumOntologyMemoryMiddleware($engine);
            $preQuantumPrompt = $laravelPrompt->prompt;
            $laravelPrompt = $quantumMiddleware->handle($laravelPrompt, fn ($p) => $p);

            // Only update context if quantum actually injected something
            if ($laravelPrompt->prompt !== $preQuantumPrompt) {
                $context->effectiveUserPrompt = $laravelPrompt->prompt;
                $context->laravelPrompt = $laravelPrompt;

                if ($context->collector && $context->sessionId) {
                    $context->collector->recordEvent(
                        $context->sessionId,
                        'quantum',
                        'quantum_harness',
                        ['enabled' => true, 'injected' => true],
                        json_encode(['status' => 'Memory envelope injected'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                }
            } else {
                $context->logger?->debug('QuantumInjectionStage: no relevant context found, prompt unchanged.');

                if ($context->collector && $context->sessionId) {
                    $context->collector->recordEvent(
                        $context->sessionId,
                        'quantum',
                        'quantum_harness',
                        ['enabled' => true, 'injected' => false],
                        json_encode(['status' => 'No relevant context found'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                    );
                }
            }
        } catch (\Throwable $e) {
            $context->logger?->warning('Quantum injection stage failed: '.$e->getMessage());

            if ($context->collector && $context->sessionId) {
                $context->collector->recordEvent(
                    $context->sessionId,
                    'quantum',
                    'quantum_harness',
                    ['enabled' => true, 'injected' => false],
                    json_encode(['status' => 'Error: '.$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                );
            }
        }

        return $context;
    }
}
