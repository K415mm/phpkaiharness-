<?php

namespace Phpkaiharness\Http\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Optimize\QuantumInferenceEngine;
use Phpkaiharness\Support\HarnessConfig;

class QuantumOntologyMemoryMiddleware
{
    protected QuantumInferenceEngine $engine;

    public function __construct(QuantumInferenceEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Handle the incoming prompt and inject quantum-inspired ontology memory.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $enabled = HarnessConfig::isNodeEnabled('quantum_harness', 'harness.quantum_harness.enabled', false);

        if (! $enabled) {
            return $next($prompt);
        }

        $latestPrompt = $prompt->prompt;

        if (! empty($latestPrompt)) {
            // 1. Infer active query phase angle dynamically from agent state/type
            $queryPhase = $this->engine->determinePhaseAngle($prompt->agent);

            // 2. Fetch Superpositioned Context + Entangled Neighborhood
            $contextEnvelope = $this->engine->synthesizeContext($latestPrompt, $queryPhase);

            // 3. Inject synthesized knowledge context into agent prompt
            if (! empty($contextEnvelope)) {
                $prompt = $prompt->append("\n\n[QUANTUM-HARNESS MEMORY ENVELOPE]:\n".$contextEnvelope);
            }
        }

        // 4. Pass the enriched prompt forward. This middleware is a prompt
        // transformer only; the AgentLoop pipeline resolves the prompt (not a
        // model response) here, so post-flight memory collapse is dispatched by
        // the AgentLoop once the real model response is available.
        return $next($prompt);
    }
}
