<?php

namespace Phpkaiharness;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\Groq\GroqGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Providers\OpenAiProvider;
use Phpkaiharness\Console\Commands\ConfigValidateCommand;
use Phpkaiharness\Console\Commands\InstallCommand;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\SemanticMemoryInterface;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Llm\LaravelAiClient;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Optimize\QuantumInferenceEngine;
use Phpkaiharness\Optimize\SqliteSemanticMemory;
use Phpkaiharness\Session\SessionManager;

/**
 * phpkaiharness Service Provider.
 *
 * Registers package configuration, views, routes, and telemetry dashboard.
 */
class PhpkaiharnessServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Merges the default package configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/harness.php',
            'harness'
        );

        // Load overrides from storage if they exist
        $overridePath = storage_path('app/phpkaiharness/config_overrides.json');
        if (File::exists($overridePath)) {
            $isTesting = false;
            if (function_exists('app')) {
                $app = app();
                if (method_exists($app, 'environment') && $app->environment('testing')) {
                    $isTesting = true;
                }
            }
            if (! $isTesting) {
                $overrides = json_decode(File::get($overridePath), true);
                if (is_array($overrides)) {
                    // Translate Windows drive letter paths in a Linux/WSL environment
                    if (DIRECTORY_SEPARATOR === '/') {
                        array_walk_recursive($overrides, function (&$value) {
                            if (is_string($value) && preg_match('/^[a-zA-Z]:\\\\/', $value)) {
                                $drive = strtolower($value[0]);
                                $translated = '/mnt/'.$drive.str_replace('\\', '/', substr($value, 2));
                                if (! file_exists($translated) && preg_match('/storage[\\\/](.+)$/', $value, $m)) {
                                    $value = storage_path(str_replace('\\', '/', $m[1]));
                                } else {
                                    $value = $translated;
                                }
                            }
                        });
                    }
                    config(['harness' => array_replace_recursive(config('harness'), $overrides)]);
                }
            }
        }

        // Register connection for Isolated SQLite Database
        $dbPath = config('harness.quantum_harness.db_path') ?: (function_exists('storage_path') ? storage_path('app/phpkaiharness/agent_memory.sqlite') : null);
        if ($dbPath) {
            config([
                'database.connections.agent_memory_sqlite' => [
                    'driver' => 'sqlite',
                    'database' => $dbPath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ]);
        }

        $this->app->singleton(SessionManager::class, function ($app) {
            return new SessionManager;
        });

        $this->app->singleton(QuantumInferenceEngine::class, function ($app) {
            $dbPath = config('harness.quantum_harness.db_path') ?: (function_exists('storage_path') ? storage_path('app/phpkaiharness/agent_memory.sqlite') : null);

            return new QuantumInferenceEngine($dbPath);
        });

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry;
            // Automatically attach tagged tools
            foreach ($app->tagged('harness.tool') as $tool) {
                $registry->attach($tool);
            }

            return $registry;
        });

        $this->app->singleton(SqliteMonitorStore::class, function ($app) {
            $dbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();

            return new SqliteMonitorStore($dbPath);
        });

        $this->app->bind(AnalyticsCollectorInterface::class, SqliteMonitorStore::class);

        $this->app->singleton(SemanticMemoryInterface::class, function ($app) {
            $dbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();

            return new SqliteSemanticMemory(
                $app->make(SqliteMonitorStore::class)->getPdo(),
                $dbPath
            );
        });

        $this->app->resolving(AiManager::class, function (AiManager $manager) {
            $manager->extend('openai', function ($app, $config) {
                $provider = new OpenAiProvider(
                    new OpenAiGateway($app['events']),
                    $config,
                    $app->make(Dispatcher::class)
                );

                $name = $config['name'] ?? '';
                if ($name === 'qwen' || $name === 'qwen_cloud') {
                    $provider->useTextGateway(new GroqGateway($app['events']));
                }

                return $provider;
            });
        });

        $this->app->bind(LlmClientInterface::class, function ($app) {
            $provider = config('harness.default.provider', 'ollama');
            $model = config('harness.default.model', 'llama3.2');

            return new LaravelAiClient($provider, $model);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * Publishes config, loads views, and registers telemetry routes.
     */
    public function boot(): void
    {
        // Publish configuration file
        $this->publishes([
            __DIR__.'/../config/harness.php' => config_path('harness.php'),
        ], 'harness-config');

        // Publish UI assets (HUD dashboard CSS/JS/plugins)
        $this->publishes([
            __DIR__.'/../ui/assets' => public_path('vendor/harness'),
        ], 'harness-assets');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'harness');

        // Register telemetry routes if enabled
        if (config('harness.telemetry.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ConfigValidateCommand::class,
            ]);
        }

        // Auto-initialize SQLite database if it doesn't exist
        try {
            $dbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();
            if ($dbPath && $dbPath !== ':memory:') {
                $dir = dirname($dbPath);
                if (! File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true, true);
                }
                if (! File::exists($dbPath)) {
                    File::put($dbPath, '');
                    // Instantiating the store forces schema creation
                    new SqliteMonitorStore($dbPath);
                }
            }

            $qDbPath = config('harness.quantum_harness.db_path') ?: (function_exists('storage_path') ? storage_path('app/phpkaiharness/agent_memory.sqlite') : null);
            if ($qDbPath && $qDbPath !== ':memory:') {
                $qDir = dirname($qDbPath);
                if (! File::isDirectory($qDir)) {
                    File::makeDirectory($qDir, 0755, true, true);
                }
                if (! File::exists($qDbPath)) {
                    File::put($qDbPath, '');
                    // Instantiating the engine forces schema creation
                    $this->app->make(QuantumInferenceEngine::class)->initSchema();
                }
            }
        } catch (\Throwable $e) {
            // Silence any errors during auto-init to prevent bootstrap failures
        }
    }
}
