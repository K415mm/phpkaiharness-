<?php

namespace Phpkaiharness\Optimize;

/**
 * Automatically optimizes system/user prompts for specific LLM architectures.
 */
class ModelPromptOptimizer
{
    /**
     * Enhance system and user prompts based on the selected model name.
     *
     * @return array{system: string, user: string}
     */
    public function optimize(string $systemPrompt, string $userPrompt, string $model): array
    {
        $enhancedSystem = $systemPrompt;
        $enhancedUser = $userPrompt;

        $modelLower = strtolower($model);

        if (str_contains($modelLower, 'qwen')) {
            $enhancedSystem = $this->optimizeForQwen($systemPrompt);
        } elseif (str_contains($modelLower, 'gemma')) {
            $enhancedSystem = $this->optimizeForGemma($systemPrompt);
        }

        return [
            'system' => $enhancedSystem,
            'user' => $enhancedUser,
        ];
    }

    /**
     * Apply Qwen-specific optimization rules (step-by-step reasoning & structured output).
     */
    protected function optimizeForQwen(string $systemPrompt): string
    {
        $qwenInstructions = "\n\n[QWEN OPTIMIZATION PROTOCOL]\n".
            "1. Before responding, output your step-by-step thinking process inside `<thought>...</thought>` tags.\n".
            "2. If you need to execute a tool, call the corresponding function from your tools list natively.\n".
            '3. Structure your final response logically and concisely.';

        if (! str_contains($systemPrompt, '[QWEN OPTIMIZATION PROTOCOL]')) {
            return $systemPrompt.$qwenInstructions;
        }

        return $systemPrompt;
    }

    /**
     * Apply Gemma-specific optimization rules (strict role boundaries & direct JSON responses).
     */
    protected function optimizeForGemma(string $systemPrompt): string
    {
        $gemmaInstructions = "\n\n[GEMMA OPTIMIZATION PROTOCOL]\n".
            "1. You are running in a strict role-bounded environment. Respond strictly as the assigned role.\n".
            "2. When generating tool call parameters, output clean JSON objects. DO NOT wrap JSON inside markdown code blocks (e.g. ```json) unless explicitly asked.\n".
            '3. Keep responses direct and structured.';

        if (! str_contains($systemPrompt, '[GEMMA OPTIMIZATION PROTOCOL]')) {
            return $systemPrompt.$gemmaInstructions;
        }

        return $systemPrompt;
    }
}
