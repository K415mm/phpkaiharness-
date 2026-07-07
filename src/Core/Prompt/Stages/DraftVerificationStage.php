<?php

namespace Phpkaiharness\Core\Prompt\Stages;

use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Optimize\DraftVerificationOrchestration;
use Phpkaiharness\Optimize\OntologicalContextInjector;

/**
 * Draft-Verification stage: optionally runs a draft-verification orchestration
 * that generates a draft response, verifies it, and produces an enhanced prompt.
 */
class DraftVerificationStage implements PromptProcessorInterface
{
    public function isEnabled(PromptContext $context): bool
    {
        if (isset($context->philosophyFlags['use_draft_verification']) && ! $context->philosophyFlags['use_draft_verification']) {
            return false;
        }

        if (! function_exists('config')) {
            return false;
        }

        try {
            // Check feature_graph first (Config UI writes here)
            $graphEnabled = config('harness.feature_graph.nodes.draft_verification.enabled');
            if ($graphEnabled !== null) {
                return (bool) $graphEnabled;
            }

            return (bool) config('harness.draft_verification.enabled', false);
        } catch (\Throwable $e) {
            $context->logger?->debug('Failed to check draft verification: '.$e->getMessage());

            return false;
        }
    }

    public function process(PromptContext $context): PromptContext
    {
        $orchestrator = new DraftVerificationOrchestration(new OntologicalContextInjector);
        $result = $orchestrator->orchestrate(
            userPrompt: $context->effectiveUserPrompt,
            systemPrompt: $context->systemPrompt,
            model: $context->effectiveModel,
            client: $context->llmClient,
            sessionId: $context->sessionId,
            collector: $context->collector
        );
        $context->effectiveUserPrompt = $result['prompt'];

        return $context;
    }
}
