<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider & Model
    |--------------------------------------------------------------------------
    |
    | Configure the default LLM provider and model identifier for agent
    | executions. Override via environment variables or at runtime.
    |
    */
    'default' => [
        'provider' => env('PHPKAIHARNESS_PROVIDER', 'qwen'),
        'model' => env('PHPKAIHARNESS_MODEL', 'qwen-plus'),
        'max_iterations' => env('PHPKAIHARNESS_MAX_ITERATIONS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover Configuration
    |--------------------------------------------------------------------------
    |
    | Define ordered fallback clients when the primary LLM client fails.
    | Each entry should specify provider and model.
    |
    */
    'failover' => [
        'enabled' => env('PHPKAIHARNESS_FAILOVER_ENABLED', false),
        'clients' => [
            // No default fallback clients — require explicit opt-in with health checks.
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Enable/disable caching and configure similarity thresholds.
    |
    */
    'cache' => [
        'enabled' => env('PHPKAIHARNESS_CACHE_ENABLED', true),
        'threshold' => env('PHPKAIHARNESS_CACHE_THRESHOLD', 0.88),
        'db_path' => env('PHPKAIHARNESS_DB') ?: (function_exists('app') && method_exists(app(), 'storagePath') ? storage_path('app/phpkaiharness/monitor.db') : null),
        'eligibility' => [
            'reject_patterns' => [
                'iteration limit',
                'iteration limit reached',
                'cURL error',
                'LLM execution error',
                '⚠️',
            ],
            'reject_empty' => true,
            'reject_min_length' => 20,
        ],
        'namespaces' => [
            'enabled' => env('PHPKAIHARNESS_CACHE_NAMESPACES', true),
        ],
        'ttl_seconds' => env('PHPKAIHARNESS_CACHE_TTL', 0),
        /*
        |--------------------------------------------------------------------------
        | Redis L1 Cache (Quantum Field Superposition Layer)
        |--------------------------------------------------------------------------
        |
        | Configures active concept density matrices, spontaneous symmetry breaking
        | via Subjective Field contexts, and dissipative temporal decay.
        |
        */
        'redis' => [
            'enabled' => env('PHPKAIHARNESS_CACHE_REDIS_ENABLED', true),
            'connection' => env('PHPKAIHARNESS_CACHE_REDIS_CONNECTION', 'default'),
            'decay_mode' => env('PHPKAIHARNESS_CACHE_DECAY_MODE', 'dissipative'),
            'subjective_field' => [
                'enabled' => env('PHPKAIHARNESS_CACHE_SUBJECTIVE_FIELD', true),
                'bias_weight' => env('PHPKAIHARNESS_CACHE_BIAS_WEIGHT', 0.15),
            ],
            'order_sensitive' => env('PHPKAIHARNESS_CACHE_ORDER_SENSITIVE', true),
        ],
        'verify_with_llm' => env('PHPKAIHARNESS_CACHE_VERIFY_WITH_LLM', true),
        'verify_model' => env('PHPKAIHARNESS_CACHE_VERIFY_MODEL', 'qwen-turbo'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PII Masking Configuration
    |--------------------------------------------------------------------------
    |
    | Configure personally identifiable information (PII) redaction patterns.
    | When enabled, outbound prompts are scanned and sensitive data is masked
    | before being sent to external LLM providers.
    |
    */
    'pii_masking' => [
        'enabled' => env('PHPKAIHARNESS_PII_ENABLED', true),
        'patterns' => [
            'EMAIL' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            'IP' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            'CREDIT_CARD' => '/\b(?:\d[ \-]*?){13,16}\b/',
            'API_KEY' => '/\b[A-Za-z0-9_\-]{32,64}\b/',
            'PHONE' => '/\b\d{3}[\s\-]?\d{3}[\s\-]?\d{4}\b/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Prevent rate limit errors (HTTP 429) by throttling requests.
    |
    */
    'rate_limiting' => [
        'enabled' => env('PHPKAIHARNESS_RATE_LIMIT_ENABLED', true),
        'requests_per_minute' => env('PHPKAIHARNESS_RPM', 60),
        'cooldown_ms' => env('PHPKAIHARNESS_COOLDOWN_MS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails Configuration
    |--------------------------------------------------------------------------
    |
    | Purpose-based scope controls and high-risk tool approval settings.
    |
    */
    'guardrails' => [
        'enabled' => env('PHPKAIHARNESS_GUARDRAILS_ENABLED', true),
        'high_risk_tools' => ['wsl_command', 'delete_*', 'execute_*', 'rm_*'],
        'authorized_scopes' => ['admin', 'sizing', 'analytics', 'read-only'],
        'tool_scope_map' => [
            'wsl_command' => ['admin'],
            'delete_*' => ['admin', 'write'],
            'execute_*' => ['admin'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Prompt Optimizer Configuration
    |--------------------------------------------------------------------------
    |
    | Automatically rewrites system prompts to match Qwen / Gemma architecture
    | strengths. Disable to send raw prompts to any model.
    |
    */
    'optimizer' => [
        'enabled' => env('PHPKAIHARNESS_OPTIMIZER_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ontological Context Injector Configuration
    |--------------------------------------------------------------------------
    |
    | RAG-based context injection using Laravel AI SDK Embeddings.
    | Fetches semantically relevant Eloquent records and prepends them
    | into the agent system prompt before each LLM call.
    |
    */
    'ontology' => [
        'enabled' => env('PHPKAIHARNESS_ONTOLOGY_ENABLED', false),
        'embedding_column' => env('PHPKAIHARNESS_ONTOLOGY_COLUMN', 'embedding'),
        'similarity_threshold' => env('PHPKAIHARNESS_ONTOLOGY_THRESHOLD', 0.30),
        'max_records' => env('PHPKAIHARNESS_ONTOLOGY_LIMIT', 3),
        'namespaces' => [
            'enabled' => env('PHPKAIHARNESS_ONTOLOGY_NAMESPACES', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Policy Guardrail Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | Laravel AI SDK middleware that validates outbound tool calls against
    | host application Gate policies (execute-tool-{name} gates).
    |
    */
    'policy_guardrail' => [
        'enabled' => env('PHPKAIHARNESS_POLICY_GUARDRAIL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Compaction Configuration
    |--------------------------------------------------------------------------
    |
    | Manage token overflow by pruning old conversation turns.
    |
    */
    'compaction' => [
        'strategy' => env('PHPKAIHARNESS_COMPACT_STRATEGY', 'sliding_window'),
        'max_turns' => env('PHPKAIHARNESS_MAX_TURNS', 6),
        'max_tokens_threshold' => env('PHPKAIHARNESS_TOKEN_THRESHOLD', 4000),
        // Compression sub-strategy for attachments and code-heavy prompts.
        'compression' => [
            'enabled' => env('PHPKAIHARNESS_COMPRESSION_ENABLED', false),
            'line_threshold' => env('PHPKAIHARNESS_COMPRESSION_THRESHOLD', 150),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Bootstrapping Configuration
    |--------------------------------------------------------------------------
    |
    | Prepends system details to the prompt before loop starts.
    |
    */
    'bootstrap' => [
        'enabled' => env('PHPKAIHARNESS_BOOTSTRAP_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thinking Budget Configuration
    |--------------------------------------------------------------------------
    |
    | Prevent runs from exceeding token budget.
    |
    */
    'budget' => [
        'enabled' => env('PHPKAIHARNESS_BUDGET_ENABLED', true),
        'max_tokens' => env('PHPKAIHARNESS_BUDGET_MAX', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cognitive memory Configuration
    |--------------------------------------------------------------------------
    |
    | Asynchronously extracts and stores facts in the cognitive graph memory.
    |
    */
    'cognitive_memory' => [
        'enabled' => env('PHPKAIHARNESS_COG_MEMORY_ENABLED', true),
        'extraction_mode' => env('PHPKAIHARNESS_COG_MEMORY_MODE', 'sync'),
        'quality_filter' => [
            'min_length' => 15,
            'reject_patterns' => ['maybe', 'might', 'possibly', 'i think', 'not sure', 'unclear', 'todo', 'tbd', 'fixme', 'hack', 'placeholder', 'lorem ipsum', 'test data', 'dummy', 'sample', 'example', 'placeholder text'],
            'reject_markdown_only' => true,
        ],
        'dedup' => [
            'enabled' => true,
            'similarity_threshold' => 0.85,
        ],
        'max_depth' => env('PHPKAIHARNESS_COG_MEMORY_MAX_DEPTH', 3),
        'coherence_threshold' => env('PHPKAIHARNESS_COG_MEMORY_COHERENCE', 0.15),
        'decay_rate' => env('PHPKAIHARNESS_COG_MEMORY_DECAY', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | Draft-Verification Pipeline Configuration
    |--------------------------------------------------------------------------
    |
    | Executes Draft -> Retrieve -> Verify loops to refine reasoning.
    |
    */
    'draft_verification' => [
        'enabled' => env('PHPKAIHARNESS_DRAFT_VERIFY_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quantum-Inspired Ontological Memory Harness Configuration
    |--------------------------------------------------------------------------
    |
    | Integrates classical adaptations of quantum state nodes, contextual phase
    | interference, and semantic entanglement.
    |
    */
    'quantum_harness' => [
        'enabled' => env('PHPKAIHARNESS_QUANTUM_ENABLED', false),
        'db_path' => env('PHPKAIHARNESS_QUANTUM_DB') ?: (function_exists('app') && method_exists(app(), 'storagePath') ? storage_path('app/phpkaiharness/agent_memory.sqlite') : null),
        'alpha' => env('PHPKAIHARNESS_QUANTUM_ALPHA', 0.7),
        'beta' => env('PHPKAIHARNESS_QUANTUM_BETA', 0.3),
        'similarity_threshold' => env('PHPKAIHARNESS_QUANTUM_THRESHOLD', 0.30),
        'max_anchors' => env('PHPKAIHARNESS_QUANTUM_LIMIT', 3),
        'coherence_decay' => env('PHPKAIHARNESS_QUANTUM_COHERENCE_DECAY', 0.05),
        'density_matrix_bias' => env('PHPKAIHARNESS_QUANTUM_DENSITY_BIAS', 0.10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qwen Cloud Custom Harness Configuration
    |--------------------------------------------------------------------------
    |
    | Activates a layer of custom harness specifically for Qwen Cloud.
    |
    */
    // Qwen Cloud provider configuration — hybrid mode:
    // At runtime, QwenClient prioritizes the host app's global_settings
    // (qwen_api_key, qwen_url, qwen_model via AiConfigHelper) and falls
    // back to these env-driven values when the host app is unavailable.
    'qwen_provider' => [
        'enabled' => env('PHPKAIHARNESS_QWEN_ENABLED', true),
        'api_key' => env('PHPKAIHARNESS_QWEN_KEY') ?: (env('QWEN_API_KEY') ?: env('DASHSCOPE_API_KEY', '')),
        'url' => env('PHPKAIHARNESS_QWEN_URL') ?: (env('QWEN_URL') ?: env('DASHSCOPE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1')),
        'model' => env('PHPKAIHARNESS_QWEN_MODEL', 'qwen-plus'),
        'light_model' => env('PHPKAIHARNESS_QWEN_LIGHT_MODEL', 'qwen-turbo'),
        'structured_output' => env('PHPKAIHARNESS_QWEN_STRUCTURED', 'json_object'),
        'max_tokens' => env('PHPKAIHARNESS_QWEN_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Graph Configuration
    |--------------------------------------------------------------------------
    |
    | Each feature node is independently toggleable. The pipeline dynamically
    | assembles only enabled nodes per request. This replaces the old fixed
    | linear pipeline with a config-based graph approach.
    |
    */

    'feature_graph' => [
        'nodes' => [
            'draft_verification' => [
                'enabled' => env('PHPKAIHARNESS_DRAFT_VERIFY_ENABLED', false),
            ],
            'environment_bootstrap' => [
                'enabled' => env('PHPKAIHARNESS_BOOTSTRAP_ENABLED', false),
            ],
            'context_compression' => [
                'enabled' => env('PHPKAIHARNESS_COMPRESSION_ENABLED', false),
            ],
            'model_optimizer' => [
                'enabled' => env('PHPKAIHARNESS_OPTIMIZER_ENABLED', false),
            ],
            'ontology_injection' => [
                'enabled' => env('PHPKAIHARNESS_ONTOLOGY_ENABLED', false),
            ],
            'semantic_cache' => [
                'enabled' => env('PHPKAIHARNESS_CACHE_ENABLED', false),
            ],
            'context_compactor' => [
                'enabled' => env('PHPKAIHARNESS_COMPACT_ENABLED', true),
            ],
            'guardrails' => [
                'enabled' => env('PHPKAIHARNESS_GUARDRAILS_ENABLED', false),
            ],
            'cognitive_memory' => [
                'enabled' => env('PHPKAIHARNESS_COG_MEMORY_ENABLED', false),
            ],
            'quantum_harness' => [
                'enabled' => env('PHPKAIHARNESS_QUANTUM_ENABLED', false),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Isolation
    |--------------------------------------------------------------------------
    |
    | When enabled, each Laravel PHP session gets its own phpkaiharness
    | session folder with a dedicated SQLite monitor DB and quantum
    | memory DB. This isolates agent traces, caches, and memory per
    | browser session for multi-tenant optimization.
    |
    */
    'session_isolation' => [
        'enabled' => env('PHPKAIHARNESS_SESSION_ISOLATION', true),
        'base_path' => env('PHPKAIHARNESS_SESSIONS_PATH'),
        'cleanup_hours' => env('PHPKAIHARNESS_SESSION_CLEANUP_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the built-in telemetry dashboard route and access controls.
    |
    */
    'telemetry' => [
        'enabled' => env('PHPKAIHARNESS_TELEMETRY_ENABLED', true),
        'route_prefix' => env('PHPKAIHARNESS_ROUTE_PREFIX', 'harness'),
        'middleware' => ['web'],
    ],
];
