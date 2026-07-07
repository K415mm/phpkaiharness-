<?php

namespace Phpkaiharness\Support;

class HarnessConfig
{
    /**
     * Check if a feature graph node or setting is enabled.
     * Priority: feature_graph.nodes.<name>.enabled > legacy config key > default
     */
    public static function isNodeEnabled(string $nodeName, ?string $legacyKey, bool $default): bool
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            // Check feature_graph first
            $graphEnabled = config("harness.feature_graph.nodes.{$nodeName}.enabled");
            if ($graphEnabled !== null) {
                return (bool) $graphEnabled;
            }

            // Fall back to legacy config key
            if ($legacyKey !== null) {
                return (bool) config($legacyKey, $default);
            }
        } catch (\Throwable $e) {
            return $default;
        }

        return $default;
    }

    /**
     * Return the active config mode.
     *
     * - 'philosophy' : The Dirac Complexity Router adapts which features are
     *                  *used* inside the loop. Cache and memory are ALWAYS
     *                  initialised and checked; a miss is telemetry, not a
     *                  teardown. The router never overwrites the manager's
     *                  feature-flag config.
     *
     * - 'force'      : The manager's saved config is used exactly as-is.
     *                  The Dirac router still classifies complexity for
     *                  telemetry, but does NOT override any feature flags.
     */
    public static function getConfigMode(): string
    {
        if (! function_exists('config')) {
            return 'force';
        }

        try {
            return (string) config('harness.config_mode', 'force');
        } catch (\Throwable $e) {
            return 'force';
        }
    }

    /**
     * Reload the saved config_overrides.json into the running Laravel config.
     *
     * Called at the start of every AgentLoop::run() to guarantee that any
     * config change saved by the manager — even mid-session — takes immediate
     * effect without requiring an Octane worker restart.
     */
    public static function reloadOverrides(): void
    {
        if (function_exists('app') && app()->runningUnitTests()) {
            return;
        }

        if (! function_exists('config')) {
            return;
        }

        try {
            $overridePath = storage_path('app/phpkaiharness/config_overrides.json');

            if (! function_exists('storage_path') || ! file_exists($overridePath)) {
                return;
            }

            $raw = file_get_contents($overridePath);
            if ($raw === false || $raw === '') {
                return;
            }

            $overrides = json_decode($raw, true);
            if (! is_array($overrides)) {
                return;
            }

            // Flatten to dot-notation and re-apply into the running config
            foreach (self::flattenArray($overrides, 'harness') as $key => $value) {
                config([$key => $value]);
            }
        } catch (\Throwable $e) {
            // Non-fatal: continue with whatever config is in memory
        }
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private static function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flattenArray($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
