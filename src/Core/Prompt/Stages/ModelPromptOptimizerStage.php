<?php

namespace Phpkaiharness\Core\Prompt\Stages;

use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Optimize\ModelPromptOptimizer;

/**
 * Model Prompt Optimizer stage: applies model-specific prompt optimization
 * to improve the system and user prompts for the target LLM.
 */
class ModelPromptOptimizerStage implements PromptProcessorInterface
{
    public function isEnabled(PromptContext $context): bool
    {
        if (isset($context->philosophyFlags['use_optimizer']) && ! $context->philosophyFlags['use_optimizer']) {
            return false;
        }

        if (! function_exists('config')) {
            return false;
        }

        try {
            // Check feature_graph first (Config UI writes here)
            $graphEnabled = config('harness.feature_graph.nodes.model_optimizer.enabled');
            if ($graphEnabled !== null) {
                return (bool) $graphEnabled;
            }

            // Fall back to legacy optimizer key — default FALSE (opt-in)
            return (bool) config('harness.optimizer.enabled', config('harness.model_prompt_optimizer.enabled', false));
        } catch (\Throwable $e) {
            $context->logger?->debug('Failed to check optimizer config: '.$e->getMessage());

            return false;
        }
    }

    public function process(PromptContext $context): PromptContext
    {
        $optimizer = new ModelPromptOptimizer;
        $optimized = $optimizer->optimize($context->systemPrompt, $context->effectiveUserPrompt, $context->effectiveModel);

        $optimizerEnabled = $this->isEnabled($context);
        $context->optimizedSystemPrompt = $optimizerEnabled ? $optimized['system'] : $context->systemPrompt;
        $context->effectiveUserPrompt = $optimized['user'];

        if ($optimizerEnabled) {
            $context->logger?->info("ModelPromptOptimizer applied for model [{$context->effectiveModel}].");
            if ($context->collector && $context->sessionId) {
                $context->collector->recordEvent(
                    $context->sessionId,
                    'optimizer',
                    $context->effectiveModel,
                    ['original_system' => $context->systemPrompt, 'original_user' => $context->userPrompt],
                    json_encode([
                        'optimized_system' => $context->optimizedSystemPrompt,
                        'optimized_user' => $context->effectiveUserPrompt,
                        'status' => $context->optimizedSystemPrompt !== $context->systemPrompt ? 'Enhanced' : 'No changes required',
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
                );
            }
        }

        return $context;
    }
}
