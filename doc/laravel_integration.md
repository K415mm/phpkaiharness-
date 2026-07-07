# Laravel 13 Integration Guide

This guide walks you through integrating `phpkaiharness` into a Laravel 13 host application. After setup, you'll have a full autonomous agent harness with 14 configurable feature layers and a live HUD telemetry dashboard.

---

## 1. Installation

You can install the package via Composer. 

### Option A: Local Development Integration
If you are developing locally and want to load the package from a folder, add the following to your host application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "packages/phpkaiharness",
        "options": {
            "symlink": true
        }
    }
],
```

Then run:
```bash
composer require kai/phpkaiharness
```

### Option B: Remote VCS or Packagist
If the package is published, install it directly:
```bash
composer require kai/phpkaiharness
```

---

## 2. Configuration & Asset Publishing

`phpkaiharness` comes with a built-in telemetry dashboard and configuration UI. To set them up, publish the package configuration and public assets:

### Step 1: Publish Configuration
```bash
php artisan vendor:publish --tag=harness-config
```
This copies the default package configuration to `config/harness.php`.

### Step 2: Publish Public Assets (Styles, JS, Plugins)
```bash
php artisan vendor:publish --tag=harness-assets
```
This publishes the HUD telemetry dashboard styles and script resources to `public/vendor/harness`.

---

## 3. Configuration Reference (`config/harness.php`)

```php
return [
    // Default LLM provider — Qwen Cloud is the default
    'default' => [
        'provider'       => env('PHPKAIHARNESS_PROVIDER', 'qwen'),
        'model'          => env('PHPKAIHARNESS_MODEL', 'qwen-plus'),
        'max_iterations' => 10,
    ],

    // Qwen Cloud provider — hybrid credential resolution
    // Reads from host app global_settings first, then harness config, then env vars
    'qwen_provider' => [
        'enabled'           => env('PHPKAIHARNESS_QWEN_ENABLED', true),
        'api_key'           => env('PHPKAIHARNESS_QWEN_KEY') ?: (env('QWEN_API_KEY') ?: env('DASHSCOPE_API_KEY', '')),
        'url'               => env('PHPKAIHARNESS_QWEN_URL') ?: (env('QWEN_URL') ?: env('DASHSCOPE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1')),
        'model'             => env('PHPKAIHARNESS_QWEN_MODEL', 'qwen-plus'),
        'light_model'       => env('PHPKAIHARNESS_QWEN_LIGHT_MODEL', 'qwen-turbo'),
        'structured_output' => env('PHPKAIHARNESS_QWEN_STRUCTURED', 'json_object'),
        'max_tokens'        => env('PHPKAIHARNESS_QWEN_MAX_TOKENS', 4096),
    ],

    // SQLite telemetry store path
    'cache' => [
        'db_path' => env('PHPKAIHARNESS_DB_PATH', database_path('harness.sqlite')),
    ],

    // ─── Feature Toggles ─────────────────────────────────────────────────────

    'semantic_cache' => [
        'enabled'   => true,
        'threshold' => 0.88,
    ],

    'pii_masking' => [
        'enabled'  => true,
        'patterns' => [
            'EMAIL'       => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            'IP'          => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
            'CREDIT_CARD' => '/\b(?:\d[ -]*?){13,16}\b/',
            'API_KEY'     => '/(?i)(api[-_]?key|secret|token)[\s]*[:=][\s]*["\']?[a-zA-Z0-9]{16,}["\']?/',
        ],
    ],

    'rate_limiting' => [
        'enabled'             => true,
        'requests_per_minute' => 60,
    ],

    'guardrails' => [
        'enabled'        => true,
        'max_arg_length' => 1024,
    ],

    'model_prompt_optimizer' => [
        'enabled' => true,
        'profile' => 'auto',   // auto | qwen | gemma | llama
    ],

    'ontological_injector' => [
        'enabled'  => true,
        'models'   => [],      // e.g. [\App\Models\Offer::class]
        'max_docs' => 5,
    ],

    'thinking_budget' => [
        'enabled'             => false,
        'max_thinking_tokens' => 8000,
    ],

    'cognitive_graph_memory' => [
        'enabled' => true,
    ],

    'draft_verification' => [
        'enabled'           => false,
        'verifier_model'    => 'qwen2.5:0.5b',
        'verifier_provider' => 'ollama',
    ],

    'context_compactor' => [
        'enabled'     => true,
        'window_size' => 6,
    ],

    'llm_failover' => [
        'enabled' => true,
    ],

    'streaming' => [
        'enabled' => false,
    ],

    // ─── Dashboard & Routes ───────────────────────────────────────────────────

    'telemetry' => [
        'enabled'      => true,
        'route_prefix' => 'harness',
        'middleware'   => ['web'],  // Add 'auth' to protect the dashboard
    ],
];
```


---

## 4. Environment Variables (`.env`)

Add the following configuration parameters to your host application's `.env` file to customize connections:

```ini
# Selected provider (qwen, ollama, lmstudio, openrouter, laravel_ai)
PHPKAIHARNESS_PROVIDER=qwen
PHPKAIHARNESS_MODEL=qwen-plus

# Qwen Cloud (DashScope) credentials
# NOTE: When integrated with a host Laravel app, these are read from
# the host's global_settings table (qwen_api_key, qwen_url, qwen_model)
# via AiConfigHelper. These env vars serve as fallback for standalone usage.
PHPKAIHARNESS_QWEN_KEY=your-dashscope-api-key
PHPKAIHARNESS_QWEN_URL=https://dashscope-intl.aliyuncs.com/compatible-mode/v1
PHPKAIHARNESS_QWEN_MODEL=qwen-plus
PHPKAIHARNESS_QWEN_LIGHT_MODEL=qwen-turbo
PHPKAIHARNESS_QWEN_STRUCTURED=json_object
PHPKAIHARNESS_QWEN_MAX_TOKENS=4096

# Ollama Endpoint URL (if used as fallback)
PHPKAIHARNESS_URL=http://localhost:11434

# LM Studio Endpoint URL (if used)
PHPKAIHARNESS_LMSTUDIO_URL=http://localhost:1234

# OpenRouter API Key (if used)
OPENROUTER_API_KEY=your-openrouter-api-key-here

# SQLite standalone telemetry store location
PHPKAIHARNESS_DB_PATH=database/harness.sqlite
```

> [!TIP]
> **Hybrid Credential Resolution:** When the host Laravel app has `ai_provider = qwen` in its `global_settings` table, `QwenClient` automatically reads `qwen_api_key`, `qwen_url`, and `qwen_model` from the database — no env vars or harness config needed. The resolution priority is:
> 1. Constructor arguments (highest)
> 2. Host app `global_settings` (via `GlobalSetting::getValue()`)
> 3. Laravel AI SDK config (`ai.providers.qwen.*`)
> 4. Harness config (`harness.qwen_provider.*`)
> 5. Environment variables (lowest)

> [!IMPORTANT]
> The database path directory must exist and be writeable by the web server process (e.g., `storage/` or `database/`). The SQLite database file will be automatically created on the first write.

---

## 5. Telemetry & Configuration Dashboard Access

Once published and configured, start your Laravel development server:
```bash
php artisan serve
```

You can now visit the following endpoints in your browser:
*   **Telemetry Dashboard**: `http://localhost:8000/harness/dashboard`
*   **Settings Configuration**: `http://localhost:8000/harness/config`

---

## 6. How to Use the Package in Your Host Application Code

Below is an example of creating a controller method or console command in your Laravel host application that invokes a `phpkaiharness` agent execution loop.

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Llm\QwenClient;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Tools\WslCommandTool;

class AgentRunController extends Controller
{
    public function executeAgent(Request $request)
    {
        $prompt = $request->input('prompt', 'Check the status of localhost');

        // 1. Resolve LLM client — QwenClient reads credentials from
        //    global_settings → harness config → env vars (hybrid mode)
        $llmClient = new QwenClient(
            defaultModel: config('harness.default.model', 'qwen-plus')
        );

        // 2. Setup Tool Registry
        $registry = new ToolRegistry();
        $registry->attach(new WslCommandTool(
            name: 'diagnose_tool',
            description: 'Runs ping or nslookup on hostnames.',
            allowedBinaries: ['ping', 'nslookup']
        ));

        // 3. Instantiate the loop orchestrator
        $agent = new AgentLoop(
            llmClient: $llmClient,
            registry: $registry,
            systemPrompt: "You are a networking utility bot. Diagnose system issues.",
            model: config('harness.default.model')
        );

        // 4. Set up Telemetry store (optional, logs execution steps)
        $dbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();
        $collector = new SqliteMonitorStore($dbPath);
        $sessionId = bin2hex(random_bytes(8));

        // 5. Run loop
        $history = [];
        $response = $agent->run($prompt, $history, $sessionId, $collector);

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'response' => $response,
        ]);
    }
}
```
---

## 7. Writing a Custom Tool

To add a new capability to the agent loop, create a class that implements `Phpkaiharness\Contracts\ToolInterface`:

```php
<?php

namespace App\Ai\Tools;

use Phpkaiharness\Contracts\ToolInterface;

class CustomWeatherTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Gets the current weather metrics for a target location.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location' => [
                    'type' => 'string',
                    'description' => 'The city or state location, e.g. San Francisco, CA'
                ]
            ],
            'required' => ['location']
        ];
    }

    public function execute(array $args): string
    {
        $location = $args['location'] ?? 'Unknown';
        
        // Custom API fetching logic...
        return json_encode([
            'location' => $location,
            'temperature' => '72F',
            'condition' => 'Sunny',
        ]);
    }
}
```

Then register it:
```php
$registry->attach(new \App\Ai\Tools\CustomWeatherTool());
```
