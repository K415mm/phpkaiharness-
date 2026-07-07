<?php

namespace Phpkaiharness\Http\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Environment Bootstrapping Middleware for laravel/ai.
 * Gathers a local system snapshot (PHP, OS, key package versions, RAM limits)
 * and prepends it to the system instructions block before the loop begins.
 */
class EnvironmentBootstrapMiddleware
{
    /**
     * Cache the bootstrapped profile in-memory to prevent slow double executions.
     */
    protected static ?string $cachedSnapshot = null;

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $enabled = HarnessConfig::isNodeEnabled('bootstrap', 'harness.bootstrap.enabled', true);
        if (! $enabled) {
            return $next($prompt);
        }

        $sessionId = function_exists('app') && app()->bound('harness.active_session_id') ? app('harness.active_session_id') : null;
        $collector = $this->resolveCollector();

        $snapshot = self::getEnvironmentSnapshot();

        // Prepend the environment snapshot to the agent system instructions / prompt
        $bootstrappedPrompt = $prompt->prepend($snapshot);

        if ($collector && $sessionId) {
            $collector->recordEvent(
                $sessionId,
                'bootstrap',
                'EnvironmentBootstrapMiddleware',
                [
                    'php_version' => PHP_VERSION,
                    'os_family' => PHP_OS_FAMILY,
                    'laravel_version' => (function_exists('app') && method_exists(app(), 'version')) ? app()->version() : 'Unknown',
                ],
                'Successfully injected [Environment Snapshot] into agent prompt context.'
            );
        }

        return $next($bootstrappedPrompt);
    }

    /**
     * Build or return the cached environment snapshot profile.
     */
    public static function getEnvironmentSnapshot(): string
    {
        if (self::$cachedSnapshot !== null) {
            return self::$cachedSnapshot;
        }

        $laravelVer = (function_exists('app') && method_exists(app(), 'version')) ? app()->version() : 'Unknown';
        $memoryLimit = ini_get('memory_limit');
        $os = PHP_OS_FAMILY;

        // Auto-detect key framework packages
        $packages = [
            'laravel/ai' => class_exists('Laravel\Ai\Ai') ? 'Present' : 'Not Loaded',
            'laravel/horizon' => class_exists('Laravel\Horizon\Horizon') ? 'Present' : 'Not Loaded',
            'laravel/boost' => class_exists('Laravel\Boost\BoostServiceProvider') ? 'Present' : 'Not Loaded',
            'pestphp/pest' => class_exists('Pest\TestSuite') ? 'Present' : 'Not Loaded',
            'phpunit/phpunit' => class_exists('PHPUnit\Framework\TestCase') ? 'Present' : 'Not Loaded',
        ];

        $packageDetails = '';
        foreach ($packages as $pkg => $status) {
            $packageDetails .= "  - {$pkg}: {$status}\n";
        }

        $snapshot = <<<'TXT'
[ENVIRONMENT SNAPSHOT]
The host system is running in a controlled sandbox with the following specs:
- Operating System: %s
- PHP Version: %s
- PHP Memory Limit: %s
- Laravel Framework Version: %s
- Installed Packages:
%s
Ensure all code adjustments and suggestions match these system constraints.
[/ENVIRONMENT SNAPSHOT]
TXT;

        self::$cachedSnapshot = sprintf(
            $snapshot,
            $os,
            PHP_VERSION,
            $memoryLimit,
            $laravelVer,
            $packageDetails
        );

        return self::$cachedSnapshot;
    }

    /**
     * Resolve the analytics collector.
     */
    protected function resolveCollector(): ?AnalyticsCollectorInterface
    {
        if (function_exists('app')) {
            try {
                if (app()->bound(AnalyticsCollectorInterface::class)) {
                    return app(AnalyticsCollectorInterface::class);
                } elseif (function_exists('config') && function_exists('app') && app()->bound('config')) {
                    $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();

                    return new SqliteMonitorStore($dbPath);
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}
