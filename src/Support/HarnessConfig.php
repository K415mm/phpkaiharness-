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
}
