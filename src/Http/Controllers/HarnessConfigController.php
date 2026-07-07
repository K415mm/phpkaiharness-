<?php

namespace Phpkaiharness\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * phpkaiharness Configuration UI Controller.
 *
 * Reads and writes the published harness.php config file,
 * enabling live enable/disable of package features from the browser.
 */
class HarnessConfigController extends Controller
{
    /**
     * Render the configuration UI.
     *
     * GET /harness/config
     */
    public function index(): Response
    {
        $config = config('harness');

        return response()->view('harness::config', compact('config'));
    }

    /**
     * Save updated configuration values.
     *
     * POST /harness/config
     */
    public function save(Request $request): RedirectResponse
    {
        $configPath = config_path('harness.php');

        if (! File::exists($configPath)) {
            return redirect()->route('harness.config')
                ->with('error', 'harness.php not found in config/. Run: php artisan vendor:publish --tag=harness-config');
        }

        $updated = $this->buildUpdatedConfig($request);

        // Write overrides JSON (for service provider to load at boot).
        // This is the primary persistence mechanism — storage/ is always
        // writable by the web server user, unlike config/ which may be
        // owned by a deploy user (e.g. root) after a git pull.
        $overridePath = storage_path('app/phpkaiharness/config_overrides.json');
        $directory = dirname($overridePath);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }
        File::put($overridePath, json_encode($updated, JSON_PRETTY_PRINT));

        // Best-effort: also write directly to config/harness.php so changes
        // are visible in the file itself. This can fail with a permission
        // error if config/ is owned by a different user (e.g. after a
        // deploy script running as root/deploy-user overwrote ownership).
        // We do not let that failure surface as a 500 — the overrides JSON
        // above already guarantees the settings take effect.
        // Clear config cache so the new file is read on next request
        $configWriteWarning = null;
        try {
            $phpConfig = $this->buildPhpConfigString($updated);
            File::put($configPath, $phpConfig);

            $cachedConfigPath = base_path('bootstrap/cache/config.php');
            if (File::exists($cachedConfigPath)) {
                File::delete($cachedConfigPath);
            }
        } catch (\Throwable $e) {
            $configWriteWarning = 'Note: config/harness.php could not be updated directly ('.$e->getMessage().'). Settings were saved to overrides and will still apply.';
        }

        // Reload config in current process
        foreach ($this->flattenConfig($updated, 'harness') as $key => $value) {
            config([$key => $value]);
        }

        // Programmatically reload Octane and Horizon workers to apply changes to all long-running processes
        if (function_exists('app')) {
            try {
                Artisan::call('config:clear');
            } catch (\Throwable $e) {
            }
            try {
                if (app()->bound('octane') || config('octane.server')) {
                    Artisan::call('octane:reload');
                }
            } catch (\Throwable $e) {
            }
            try {
                Artisan::call('horizon:terminate');
            } catch (\Throwable $e) {
            }
        }

        $message = $configWriteWarning ?? 'Configuration saved, config cache cleared, and workers reloaded.';

        return redirect()->route('harness.config')
            ->with($configWriteWarning ? 'warning' : 'success', $message);
    }

    /**
     * Build a PHP config file string from the config array.
     *
     * @param  array<string, mixed>  $config
     */
    private function buildPhpConfigString(array $config): string
    {
        $export = $this->varExportPretty($config, 1);

        return "<?php\n\nreturn {$export};\n";
    }

    /**
     * Export a PHP array with clean indentation (no numeric keys for associative arrays).
     */
    private function varExportPretty(mixed $value, int $indent = 1): string
    {
        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }

            $isAssoc = ! array_is_list($value);
            $pad = str_repeat('    ', $indent);
            $lines = [];
            foreach ($value as $key => $item) {
                $keyStr = $isAssoc ? "'{$key}' => " : '';
                $lines[] = $pad.$keyStr.$this->varExportPretty($item, $indent + 1);
            }

            $closePad = str_repeat('    ', $indent - 1);

            return "[\n".implode(",\n", $lines).",\n".$closePad.']';
        }
        if (is_string($value)) {
            return "'".str_replace("'", "\\'", $value)."'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }

        return var_export($value, true);
    }

    /**
     * Build the updated config array from POST data.
     *
     * @return array<string, mixed>
     */
    private function buildUpdatedConfig(Request $request): array
    {
        return [
            'default' => [
                'provider' => $request->input('default_provider', config('harness.default.provider')),
                'model' => $request->input('default_model', config('harness.default.model')),
                'max_iterations' => (int) $request->input('default_max_iterations', config('harness.default.max_iterations')),
            ],
            'failover' => [
                'enabled' => $request->boolean('failover_enabled'),
                'clients' => config('harness.failover.clients'),
            ],
            'feature_graph' => [
                'nodes' => [
                    'draft_verification' => ['enabled' => $request->boolean('fg_draft_verification')],
                    'environment_bootstrap' => ['enabled' => $request->boolean('fg_environment_bootstrap')],
                    'context_compression' => ['enabled' => $request->boolean('fg_context_compression')],
                    'model_optimizer' => ['enabled' => $request->boolean('fg_model_optimizer')],
                    'ontology_injection' => ['enabled' => $request->boolean('fg_ontology_injection')],
                    'semantic_cache' => ['enabled' => $request->boolean('fg_semantic_cache')],
                    'context_compactor' => ['enabled' => $request->boolean('fg_context_compactor')],
                    'guardrails' => ['enabled' => $request->boolean('fg_guardrails')],
                    'cognitive_memory' => ['enabled' => $request->boolean('fg_cognitive_memory')],
                    'quantum_harness' => ['enabled' => $request->boolean('fg_quantum_harness')],
                ],
            ],
            'cache' => [
                'enabled' => $request->boolean('cache_enabled'),
                'threshold' => (float) $request->input('cache_threshold', config('harness.cache.threshold')),
                'db_path' => config('harness.cache.db_path'),
                'redis' => [
                    'enabled' => $request->boolean('cache_redis_enabled'),
                    'connection' => $request->input('cache_redis_connection', config('harness.cache.redis.connection', 'default')),
                    'decay_mode' => $request->input('cache_redis_decay_mode', config('harness.cache.redis.decay_mode', 'dissipative')),
                    'subjective_field' => [
                        'enabled' => $request->boolean('cache_redis_subjective_field_enabled'),
                        'bias_weight' => (float) $request->input('cache_redis_subjective_field_bias_weight', config('harness.cache.redis.subjective_field.bias_weight', 0.15)),
                    ],
                    'order_sensitive' => $request->boolean('cache_redis_order_sensitive'),
                ],
                'verify_with_llm' => $request->boolean('cache_verify_with_llm'),
                'verify_model' => config('harness.cache.verify_model', 'qwen-turbo'),
            ],
            'pii_masking' => [
                'enabled' => $request->boolean('pii_masking_enabled'),
                'patterns' => config('harness.pii_masking.patterns'),
            ],
            'rate_limiting' => [
                'enabled' => $request->boolean('rate_limiting_enabled'),
                'requests_per_minute' => (int) $request->input('rate_limiting_rpm', config('harness.rate_limiting.requests_per_minute')),
                'cooldown_ms' => (int) $request->input('rate_limiting_cooldown_ms', config('harness.rate_limiting.cooldown_ms')),
            ],
            'guardrails' => [
                'enabled' => $request->boolean('guardrails_enabled'),
                'high_risk_tools' => config('harness.guardrails.high_risk_tools'),
                'authorized_scopes' => config('harness.guardrails.authorized_scopes'),
                'tool_scope_map' => config('harness.guardrails.tool_scope_map'),
            ],
            'optimizer' => [
                'enabled' => $request->boolean('optimizer_enabled'),
            ],
            'ontology' => [
                'enabled' => $request->boolean('ontology_enabled'),
                'embedding_column' => $request->input('ontology_embedding_column', config('harness.ontology.embedding_column')),
                'similarity_threshold' => (float) $request->input('ontology_similarity_threshold', config('harness.ontology.similarity_threshold')),
                'max_records' => (int) $request->input('ontology_max_records', config('harness.ontology.max_records')),
                'db_path' => config('harness.ontology.db_path'),
                'namespaces' => [
                    'enabled' => $request->boolean('ontology_namespaces_enabled'),
                ],
            ],
            'policy_guardrail' => [
                'enabled' => $request->boolean('policy_guardrail_enabled'),
            ],
            'compaction' => [
                'strategy' => $request->input('compaction_strategy', config('harness.compaction.strategy')),
                'max_turns' => (int) $request->input('compaction_max_turns', config('harness.compaction.max_turns')),
                'max_tokens_threshold' => (int) $request->input('compaction_max_tokens_threshold', config('harness.compaction.max_tokens_threshold')),
                'compression' => [
                    'enabled' => $request->boolean('compression_enabled'),
                    'line_threshold' => (int) $request->input('compression_line_threshold', config('harness.compaction.compression.line_threshold', 150)),
                ],
            ],
            'bootstrap' => [
                'enabled' => $request->boolean('bootstrap_enabled'),
            ],
            'budget' => [
                'enabled' => $request->boolean('budget_enabled'),
                'max_tokens' => (int) $request->input('budget_max_tokens', config('harness.budget.max_tokens', 30000)),
            ],
            'cognitive_memory' => [
                'enabled' => $request->boolean('cognitive_memory_enabled'),
                'max_depth' => (int) $request->input('cognitive_memory_max_depth', config('harness.cognitive_memory.max_depth', 3)),
                'coherence_threshold' => (float) $request->input('cognitive_memory_coherence_threshold', config('harness.cognitive_memory.coherence_threshold', 0.15)),
                'decay_rate' => (float) $request->input('cognitive_memory_decay_rate', config('harness.cognitive_memory.decay_rate', 0.05)),
            ],
            'draft_verification' => [
                'enabled' => $request->boolean('draft_verification_enabled'),
            ],
            'quantum_harness' => [
                'enabled' => $request->boolean('quantum_harness_enabled'),
                'db_path' => config('harness.quantum_harness.db_path'),
                'alpha' => (float) $request->input('quantum_alpha', config('harness.quantum_harness.alpha', 0.7)),
                'beta' => (float) $request->input('quantum_beta', config('harness.quantum_harness.beta', 0.3)),
                'similarity_threshold' => (float) $request->input('quantum_similarity_threshold', config('harness.quantum_harness.similarity_threshold', 0.30)),
                'max_anchors' => (int) $request->input('quantum_max_anchors', config('harness.quantum_harness.max_anchors', 3)),
                'coherence_decay' => (float) $request->input('quantum_coherence_decay', config('harness.quantum_harness.coherence_decay', 0.05)),
                'density_matrix_bias' => (float) $request->input('quantum_density_matrix_bias', config('harness.quantum_harness.density_matrix_bias', 0.10)),
            ],
            'qwen_provider' => [
                'enabled' => $request->boolean('qwen_provider_enabled'),
                'api_key' => $request->input('qwen_provider_api_key', config('harness.qwen_provider.api_key', '')),
                'url' => $request->input('qwen_provider_url', config('harness.qwen_provider.url', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1')),
                'model' => $request->input('qwen_provider_model', config('harness.qwen_provider.model', 'qwen-plus')),
                'light_model' => $request->input('qwen_provider_light_model', config('harness.qwen_provider.light_model', 'qwen-turbo')),
                'structured_output' => $request->input('qwen_provider_structured_output', config('harness.qwen_provider.structured_output', 'json_object')),
                'max_tokens' => (int) $request->input('qwen_provider_max_tokens', config('harness.qwen_provider.max_tokens', 4096)),
            ],
            'session_isolation' => [
                'enabled' => $request->boolean('session_isolation_enabled'),
                'base_path' => $request->input('session_isolation_base_path', config('harness.session_isolation.base_path')),
                'cleanup_hours' => (int) $request->input('session_isolation_cleanup_hours', config('harness.session_isolation.cleanup_hours', 24)),
            ],
            'telemetry' => [
                'enabled' => $request->boolean('telemetry_enabled'),
                'route_prefix' => $request->input('telemetry_route_prefix', config('harness.telemetry.route_prefix', 'harness')),
                'middleware' => config('harness.telemetry.middleware'),
            ],
        ];
    }

    /**
     * Flatten a nested config array into dot-notation keys.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function flattenConfig(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenConfig($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
