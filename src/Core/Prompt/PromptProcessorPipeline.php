<?php

namespace Phpkaiharness\Core\Prompt;

use Phpkaiharness\Contracts\PromptProcessorInterface;
use Phpkaiharness\Core\Prompt\Stages\DraftVerificationStage;
use Phpkaiharness\Core\Prompt\Stages\ModelPromptOptimizerStage;
use Phpkaiharness\Core\Prompt\Stages\OntologicalContextInjectorStage;
use Phpkaiharness\Core\Prompt\Stages\PromptMiddlewareStage;
use Phpkaiharness\Core\Prompt\Stages\QuantumInjectionStage;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Dynamically assembles and runs prompt processing stages based on config.
 *
 * Each stage is independently toggleable via the feature_graph config:
 *   config('harness.feature_graph.nodes.<node_name>.enabled')
 *
 * Falls back to legacy config keys (e.g. harness.optimizer.enabled) for
 * backward compatibility. Stages only run if their config node is enabled.
 */
class PromptProcessorPipeline
{
    /** @var array<int, PromptProcessorInterface>|null */
    private ?array $stages;

    /**
     * @param  array<int, PromptProcessorInterface>|null  $stages  Override stages (null = auto-assemble from config, [] = no stages).
     */
    public function __construct(?array $stages = null)
    {
        $this->stages = $stages;
    }

    public function run(PromptContext $context): PromptContext
    {
        $stages = $this->stages ?? $this->assembleStages();

        foreach ($stages as $stage) {
            if ($stage->isEnabled($context)) {
                $context = $stage->process($context);
            }
        }

        return $context;
    }

    /**
     * Dynamically assemble stages based on config graph.
     * Each stage checks its own config — only enabled stages are instantiated.
     *
     * @return array<int, PromptProcessorInterface>
     */
    protected function assembleStages(): array
    {
        $stages = [];

        // Each stage is only added if its feature_graph node is enabled
        // (or falls back to legacy config). The stage's isEnabled() method
        // does the final check, but we avoid instantiating disabled stages.

        if ($this->isNodeEnabled('draft_verification', 'harness.draft_verification.enabled', false)) {
            $stages[] = new DraftVerificationStage;
        }

        if ($this->isNodeEnabled('environment_bootstrap', 'harness.bootstrap.enabled', false)
            || $this->isNodeEnabled('context_compression', 'harness.compaction.compression.enabled', false)) {
            $stages[] = new PromptMiddlewareStage;
        }

        if ($this->isNodeEnabled('model_optimizer', 'harness.optimizer.enabled', false)) {
            $stages[] = new ModelPromptOptimizerStage;
        }

        if ($this->isNodeEnabled('ontology_injection', 'harness.ontology.enabled', false)) {
            $stages[] = new OntologicalContextInjectorStage;
        }

        // Quantum injection runs independently of bootstrap/compression stages
        if ($this->isQuantumEnabled()) {
            $stages[] = new QuantumInjectionStage;
        }

        return $stages;
    }

    /**
     * Check if quantum harness is enabled.
     */
    protected function isQuantumEnabled(): bool
    {
        return HarnessConfig::isNodeEnabled('quantum_harness', 'harness.quantum_harness.enabled', false);
    }

    /**
     * Check if a feature graph node is enabled.
     */
    protected function isNodeEnabled(string $nodeName, ?string $legacyKey, bool $default): bool
    {
        return HarnessConfig::isNodeEnabled($nodeName, $legacyKey, $default);
    }
}
