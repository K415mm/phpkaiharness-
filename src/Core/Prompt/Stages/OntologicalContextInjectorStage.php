<?php

namespace Phpkaiharness\Core\Prompt\Stages;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\DummyTextProvider;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Optimize\OntologicalContextInjector;

/**
 * Ontological Context Injector stage: queries the host application's
 * domain models for semantically similar records and injects them
 * into the system prompt as additional context.
 */
class OntologicalContextInjectorStage implements PromptProcessorInterface
{
    public function isEnabled(PromptContext $context): bool
    {
        if (isset($context->philosophyFlags['use_ontology']) && ! $context->philosophyFlags['use_ontology']) {
            return false;
        }

        if (! function_exists('config')) {
            return false;
        }

        try {
            $graphEnabled = config('harness.feature_graph.nodes.ontology_injection.enabled');
            $enabled = $graphEnabled !== null
                ? (bool) $graphEnabled
                : (bool) config('harness.ontology.enabled', config('harness.ontological_injector.enabled', false));
            $modelClass = (string) config('harness.ontology.model_class', config('harness.ontological_injector.model_class', 'App\\Models\\ClientAsset'));

            return $enabled && ! empty($modelClass) && class_exists($modelClass);
        } catch (\Throwable $e) {
            $context->logger?->debug('Failed to check ontology config: '.$e->getMessage());

            return false;
        }
    }

    public function process(PromptContext $context): PromptContext
    {
        $ontologyModelClass = (string) config('harness.ontology.model_class', config('harness.ontological_injector.model_class', 'App\\Models\\ClientAsset'));

        try {
            $injector = new OntologicalContextInjector;
            $tempPrompt = ($context->laravelPrompt instanceof AgentPrompt)
                ? $context->laravelPrompt->revise($context->effectiveUserPrompt)
                : new AgentPrompt(
                    agent: class_exists($context->agentName) ? new $context->agentName : new AnonymousAgent('', [], []),
                    prompt: $context->effectiveUserPrompt,
                    attachments: [],
                    provider: new DummyTextProvider,
                    model: $context->effectiveModel
                );
            $embeddingColumn = config('harness.ontology.embedding_column', 'embedding');
            $similarityThreshold = (float) config('harness.ontology.similarity_threshold', 0.30);
            $maxRecords = (int) config('harness.ontology.max_records', 3);

            $metadata = [];
            $injectedPrompt = $injector->inject(
                $tempPrompt,
                $ontologyModelClass,
                $embeddingColumn,
                $similarityThreshold,
                $maxRecords,
                $metadata
            );

            $injected = false;
            if ($injectedPrompt->prompt !== $context->effectiveUserPrompt) {
                $diff = str_replace($context->effectiveUserPrompt, '', $injectedPrompt->prompt);
                if (! empty($diff)) {
                    $context->optimizedSystemPrompt .= "\n".$diff;
                    $context->logger?->info("OntologicalContextInjector injected context from [{$ontologyModelClass}].");
                    if ($context->collector && $context->sessionId) {
                        $context->collector->recordEvent(
                            $context->sessionId,
                            'ontology',
                            $ontologyModelClass,
                            [
                                'query' => $context->effectiveUserPrompt,
                                'embedding_provider' => $metadata['embedding_provider'] ?? 'unknown',
                                'similarity_threshold' => $similarityThreshold,
                                'evaluated_records_count' => count($metadata['evaluated_records'] ?? []),
                                'evaluated_records' => $metadata['evaluated_records'] ?? [],
                            ],
                            json_encode([
                                'injected_context' => $diff,
                                'status' => 'Injected',
                            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                        );
                        $injected = true;
                    }
                }
            }

            if (! $injected && $context->collector && $context->sessionId) {
                $errorMessage = $metadata['error'] ?? null;
                $payloadData = [
                    'query' => $context->effectiveUserPrompt,
                    'embedding_provider' => $metadata['embedding_provider'] ?? 'unknown',
                    'similarity_threshold' => $similarityThreshold,
                    'evaluated_records_count' => count($metadata['evaluated_records'] ?? []),
                    'evaluated_records' => $metadata['evaluated_records'] ?? [],
                ];

                if ($errorMessage !== null) {
                    $payloadData['error'] = $errorMessage;
                    $responseStr = json_encode([
                        'status' => 'Error',
                        'error' => $errorMessage,
                        'message' => 'Ontological context injection failed: '.$errorMessage,
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
                } else {
                    $responseStr = json_encode([
                        'status' => 'Skipped',
                        'message' => "Checked {$ontologyModelClass}: 0 records met the similarity threshold of {$similarityThreshold}. No context injected.",
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
                }

                $context->collector->recordEvent(
                    $context->sessionId,
                    'ontology',
                    $ontologyModelClass,
                    $payloadData,
                    $responseStr
                );
            }
        } catch (\Throwable $e) {
            $context->logger?->warning('Ontological context injection failed: '.$e->getMessage());

            if ($context->collector && $context->sessionId) {
                $context->collector->recordEvent(
                    $context->sessionId,
                    'ontology',
                    $ontologyModelClass ?? 'App\\Models\\ClientAsset',
                    [
                        'query' => $context->effectiveUserPrompt,
                        'error' => $e->getMessage(),
                    ],
                    json_encode([
                        'status' => 'Error',
                        'error' => $e->getMessage(),
                        'message' => 'Stage processing failed: '.$e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                );
            }
        }

        return $context;
    }
}
