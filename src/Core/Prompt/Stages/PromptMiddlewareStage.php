<?php

namespace Phpkaiharness\Core\Prompt\Stages;

use Illuminate\Pipeline\Pipeline;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\DummyTextProvider;
use Phpkaiharness\Core\Prompt\PromptContext;
use Phpkaiharness\Http\Middleware\CompressContextMiddleware;
use Phpkaiharness\Http\Middleware\EnvironmentBootstrapMiddleware;

/**
 * Prompt Middleware stage: runs the Laravel Pipeline-based prompt preprocessing
 * (environment bootstrap, context compression, quantum ontology memory).
 *
 * Falls back to manual middleware execution in standalone/CLI environments
 * where the Laravel app container is unavailable.
 */
class PromptMiddlewareStage implements PromptProcessorInterface
{
    public function isEnabled(PromptContext $context): bool
    {
        return function_exists('config');
    }

    public function process(PromptContext $context): PromptContext
    {
        $dummyProvider = new DummyTextProvider;

        try {
            $laravelPrompt = new AgentPrompt(
                agent: class_exists($context->agentName) ? new $context->agentName : new AnonymousAgent('', [], []),
                prompt: $context->effectiveUserPrompt,
                attachments: [],
                provider: $dummyProvider,
                model: $context->effectiveModel
            );

            $pipeline = new Pipeline(app());
            $middlewares = [
                EnvironmentBootstrapMiddleware::class,
                CompressContextMiddleware::class,
            ];

            $finalLaravelPrompt = $pipeline->send($laravelPrompt)
                ->through($middlewares)
                ->then(fn ($p) => $p);

            $context->effectiveUserPrompt = $finalLaravelPrompt->prompt;
            $context->laravelPrompt = $finalLaravelPrompt;
        } catch (\Throwable $e) {
            $context->logger?->warning('Laravel pipeline prompt preprocessing failed, falling back to standalone: '.$e->getMessage());

            try {
                $dummyAgent = new class implements Agent
                {
                    use Promptable;

                    public function instructions(): \Stringable|string
                    {
                        return '';
                    }
                };
                $laravelPrompt = new AgentPrompt($dummyAgent, $context->effectiveUserPrompt, [], $dummyProvider, $context->effectiveModel);

                $bootstrap = new EnvironmentBootstrapMiddleware;
                $compress = new CompressContextMiddleware;

                $laravelPrompt = $bootstrap->handle($laravelPrompt, fn ($p) => $p);
                $laravelPrompt = $compress->handle($laravelPrompt, fn ($p) => $p);

                $context->effectiveUserPrompt = $laravelPrompt->prompt;
                $context->laravelPrompt = $laravelPrompt;
            } catch (\Throwable $e2) {
                $context->logger?->warning('Standalone environment fallback failed: '.$e2->getMessage());
            }
        }

        return $context;
    }
}
