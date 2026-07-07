<?php

namespace Phpkaiharness\Console\Commands;

use Illuminate\Console\Command;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Support\HarnessConfig;

class ConfigValidateCommand extends Command
{
    protected $signature = 'harness:config-validate';

    protected $description = 'Validate phpkaiharness configuration for common issues';

    public function handle(): int
    {
        $this->info('Validating phpkaiharness configuration...');

        $errors = [];
        $warnings = [];

        // 1. Check failover config
        $failoverEnabled = (bool) config('harness.failover.enabled', false);
        $failoverClients = (array) config('harness.failover.clients', []);

        if ($failoverEnabled && empty($failoverClients)) {
            $errors[] = 'Failover is enabled but no failover clients are configured.';
        }

        if ($failoverEnabled && ! empty($failoverClients)) {
            foreach ($failoverClients as $i => $client) {
                $provider = $client['provider'] ?? '';
                $model = $client['model'] ?? '';
                if (empty($provider) || empty($model)) {
                    $errors[] = "Failover client #{$i} has missing provider or model.";
                }
                if (in_array($provider, ['lmstudio', 'ollama']) && $failoverEnabled) {
                    $warnings[] = "Failover client #{$i} uses local provider '{$provider}' — ensure it is running and has the model loaded.";
                }
            }
        }

        // 2. Check SQLite DB paths
        $cacheDbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();
        $quantumDbPath = config('harness.quantum_harness.db_path');

        if ($cacheDbPath && ! str_ends_with($cacheDbPath, '.db')) {
            $warnings[] = "Cache DB path does not end with '.db': {$cacheDbPath}";
        }

        $cacheDir = dirname($cacheDbPath);
        if (! is_dir($cacheDir)) {
            $warnings[] = "Cache DB directory does not exist: {$cacheDir} — will be created on first use.";
        }

        if ($quantumDbPath) {
            $quantumDir = dirname($quantumDbPath);
            if (! is_dir($quantumDir)) {
                $warnings[] = "Quantum DB directory does not exist: {$quantumDir} — will be created on first use.";
            }
        }

        // 3. Check for deprecated config keys
        if (config('harness.qwen_harness.enabled') !== null) {
            $warnings[] = "Deprecated config key 'harness.qwen_harness.enabled' found — use 'harness.qwen_provider' instead.";
        }

        if (config('harness.compression.enabled') !== null) {
            $warnings[] = "Deprecated top-level config key 'harness.compression' found — use 'harness.compaction.compression' instead.";
        }

        if (config('harness.feature_graph.nodes.prompt_middleware.enabled') !== null) {
            $warnings[] = "Deprecated feature_graph node 'prompt_middleware' found — use 'environment_bootstrap' and 'context_compression' instead.";
        }

        // 4. Check feature_graph node consistency
        $nodes = config('harness.feature_graph.nodes', []);
        $knownNodes = [
            'draft_verification', 'environment_bootstrap', 'context_compression',
            'model_optimizer', 'ontology_injection', 'semantic_cache',
            'context_compactor', 'guardrails', 'cognitive_memory', 'quantum_harness',
        ];

        $unknownNodes = array_diff(array_keys($nodes), $knownNodes);
        foreach ($unknownNodes as $node) {
            $warnings[] = "Unknown feature_graph node '{$node}' — not recognized by the pipeline.";
        }

        // 5. Check cache threshold
        $threshold = (float) config('harness.cache.threshold', 0.88);
        if ($threshold < 0.5 || $threshold > 1.0) {
            $warnings[] = "Cache threshold {$threshold} is outside recommended range [0.5, 1.0].";
        }

        // 6. Check compaction strategy
        $strategy = config('harness.compaction.strategy', 'sliding_window');
        if (! in_array($strategy, ['sliding_window', 'summarize', 'trim_oldest', 'none'])) {
            $errors[] = "Invalid compaction strategy '{$strategy}' — must be one of: sliding_window, summarize, trim_oldest, none.";
        }

        // 7. Check max_iterations
        $maxIter = (int) config('harness.default.max_iterations', 10);
        if ($maxIter < 1 || $maxIter > 50) {
            $warnings[] = "max_iterations is {$maxIter} — recommended range is [1, 50].";
        }

        // 8. Check cognitive_memory extraction_mode
        $extractionMode = config('harness.cognitive_memory.extraction_mode', 'sync');
        if (! in_array($extractionMode, ['off', 'sync', 'queued', 'sampled'])) {
            $warnings[] = "Unknown cognitive_memory extraction_mode '{$extractionMode}' — must be one of: off, sync, queued, sampled.";
        }

        // 9. Print resolved feature matrix
        $this->newLine();
        $this->info('Resolved Feature Matrix:');
        $features = [
            'semantic_cache', 'context_compactor', 'guardrails', 'cognitive_memory',
            'quantum_harness', 'draft_verification', 'model_optimizer', 'ontology_injection',
            'environment_bootstrap', 'context_compression',
        ];
        $matrixRows = [];
        foreach ($features as $feature) {
            $enabled = HarnessConfig::isNodeEnabled($feature, null, false);
            $matrixRows[] = [$feature, $enabled ? '<fg=green>ENABLED</>' : '<fg=gray>disabled</>'];
        }
        $matrixRows[] = ['failover', $failoverEnabled ? '<fg=green>ENABLED</>' : '<fg=gray>disabled</>'];
        $matrixRows[] = ['pii_masking', config('harness.pii_masking.enabled', false) ? '<fg=green>ENABLED</>' : '<fg=gray>disabled</>'];
        $matrixRows[] = ['rate_limiting', config('harness.rate_limiting.enabled', false) ? '<fg=green>ENABLED</>' : '<fg=gray>disabled</>'];
        $matrixRows[] = ['budget', config('harness.budget.enabled', false) ? '<fg=green>ENABLED</>' : '<fg=gray>disabled</>'];
        $this->table(['Feature', 'Status'], $matrixRows);

        // 10. Report errors and warnings
        if (! empty($errors)) {
            $this->newLine();
            $this->error('Configuration Errors:');
            foreach ($errors as $error) {
                $this->line("  <fg=red>✗</> {$error}");
            }
        }

        if (! empty($warnings)) {
            $this->newLine();
            $this->warn('Configuration Warnings:');
            foreach ($warnings as $warning) {
                $this->line("  <fg=yellow>⚠</> {$warning}");
            }
        }

        if (empty($errors) && empty($warnings)) {
            $this->newLine();
            $this->info('✓ Configuration is valid — no errors or warnings.');

            return self::SUCCESS;
        }

        if (empty($errors)) {
            $this->newLine();
            $this->info('✓ No blocking errors found, but review warnings above.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
