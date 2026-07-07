<?php
require __DIR__.'/bootstrap.php';

// Resolve config file path — prefer the host app's config, then the package default
$configPath = null;
$possibleConfigs = [
    __DIR__.'/../../../config/harness.php',
    '/mnt/s/elasticcost/config/harness.php',
    '/home/kais/elasticcost/config/harness.php',
];
foreach ($possibleConfigs as $path) {
    if (file_exists($path)) {
        $configPath = realpath($path);
        break;
    }
}
if (! $configPath) {
    $configPath = realpath(__DIR__.'/../config/harness.php');
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! $configPath || ! file_exists($configPath)) {
        $errorMessage = 'Configuration file not found: '.($configPath ?: 'config/harness.php');
    } else {
        $updated = [
            'default' => [
                'provider' => $_POST['default_provider'] ?? 'ollama',
                'model' => $_POST['default_model'] ?? '',
                'max_iterations' => (int) ($_POST['default_max_iterations'] ?? 10),
            ],
            'failover' => [
                'enabled' => isset($_POST['failover_enabled']),
                'clients' => config('harness.failover.clients') ?? [
                    ['provider' => 'ollama',   'model' => 'llama3.2'],
                    ['provider' => 'lmstudio', 'model' => 'gemma-2b-it'],
                ],
            ],
            'cache' => [
                'enabled' => isset($_POST['cache_enabled']),
                'threshold' => (float) ($_POST['cache_threshold'] ?? 0.88),
                'db_path' => config('harness.cache.db_path'),
            ],
            'pii_masking' => [
                'enabled' => isset($_POST['pii_masking_enabled']),
                'patterns' => config('harness.pii_masking.patterns') ?? [
                    'EMAIL' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
                    'IP' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
                ],
            ],
            'rate_limiting' => [
                'enabled' => isset($_POST['rate_limiting_enabled']),
                'requests_per_minute' => (int) ($_POST['rate_limiting_rpm'] ?? 60),
                'cooldown_ms' => (int) ($_POST['rate_limiting_cooldown_ms'] ?? 0),
            ],
            'guardrails' => [
                'enabled' => isset($_POST['guardrails_enabled']),
                'high_risk_tools' => config('harness.guardrails.high_risk_tools') ?? ['wsl_command', 'delete_*', 'execute_*'],
                'authorized_scopes' => config('harness.guardrails.authorized_scopes') ?? ['admin', 'sizing', 'analytics', 'read-only'],
                'tool_scope_map' => config('harness.guardrails.tool_scope_map') ?? ['wsl_command' => ['admin']],
            ],
            'optimizer' => [
                'enabled' => isset($_POST['optimizer_enabled']),
            ],
            'ontology' => [
                'enabled' => isset($_POST['ontology_enabled']),
                'embedding_column' => $_POST['ontology_embedding_column'] ?? (config('harness.ontology.embedding_column') ?? 'embedding'),
                'similarity_threshold' => (float) ($_POST['ontology_similarity_threshold'] ?? 0.30),
                'max_records' => (int) ($_POST['ontology_max_records'] ?? 3),
            ],
            'policy_guardrail' => [
                'enabled' => isset($_POST['policy_guardrail_enabled']),
            ],
            'compaction' => [
                'strategy' => $_POST['compaction_strategy'] ?? 'sliding_window',
                'max_turns' => (int) ($_POST['compaction_max_turns'] ?? 6),
                'max_tokens_threshold' => (int) ($_POST['compaction_max_tokens_threshold'] ?? 4000),
                'compression' => [
                    'enabled' => isset($_POST['compression_enabled']),
                    'line_threshold' => (int) ($_POST['compression_line_threshold'] ?? 150),
                ],
            ],
            'bootstrap' => [
                'enabled' => isset($_POST['bootstrap_enabled']),
            ],
            'budget' => [
                'enabled' => isset($_POST['budget_enabled']),
                'max_tokens' => (int) ($_POST['budget_max_tokens'] ?? 30000),
            ],
            'cognitive_memory' => [
                'enabled' => isset($_POST['cognitive_memory_enabled']),
            ],
            'draft_verification' => [
                'enabled' => isset($_POST['draft_verification_enabled']),
            ],
            'telemetry' => [
                'enabled' => isset($_POST['telemetry_enabled']),
                'route_prefix' => $_POST['telemetry_route_prefix'] ?? (config('harness.telemetry.route_prefix') ?? 'harness'),
                'middleware' => config('harness.telemetry.middleware') ?? ['web'],
            ],
        ];

        $overridePath = null;
        if (function_exists('storage_path') && function_exists('app') && method_exists(app(), 'storagePath')) {
            $overridePath = storage_path('app/phpkaiharness/config_overrides.json');
        } else {
            if ($configPath) {
                $overridePath = dirname($configPath).DIRECTORY_SEPARATOR.'harness_overrides.json';
            }
        }

        if ($overridePath) {
            $directory = dirname($overridePath);
            if (! is_dir($directory)) {
                @mkdir($directory, 0777, true);

            }
            if (file_put_contents($overridePath, json_encode($updated, JSON_PRETTY_PRINT)) !== false) {
                $successMessage = 'Configuration saved successfully.';
                foreach ($updated as $section => $vals) {
                    if (is_array($vals)) {
                        foreach ($vals as $k => $v) {
                            config(["harness.{$section}.{$k}" => $v]);
                        }
                    }
                }
            } else {
                $errorMessage = 'Failed to write configuration overrides file.';
            }
        } else {
            $errorMessage = 'Failed to resolve configuration path.';
        }
    }
}

$config = config('harness');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>phpkaiharness — Configuration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="phpkaiharness Configuration">
    <!-- HUD Core CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/plugins/bootstrap-icons/font/bootstrap-icons.css">
    <style>
        ::-webkit-scrollbar { width:5px; height:5px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.15); border-radius:4px; }
    </style>
</head>
<body>
<!-- BEGIN #app -->
<div id="app" class="app">

    <!-- ═══════════════════════════════════════════ HEADER -->
    <div id="header" class="app-header">
        <div class="desktop-toggler">
            <button type="button" class="menu-toggler"
                data-toggle-class="app-sidebar-collapsed"
                data-dismiss-class="app-sidebar-toggled"
                data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="mobile-toggler">
            <button type="button" class="menu-toggler"
                data-toggle-class="app-sidebar-mobile-toggled"
                data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="brand">
            <a href="/" class="brand-logo">
                <span class="brand-img"><span class="brand-img-text text-theme">K</span></span>
                <span class="brand-text">phpkaiharness</span>
            </a>
        </div>
        <div class="menu">
            <div class="menu-item">
                <a href="/" class="menu-link">
                    <div class="menu-icon"><i class="bi bi-cpu nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Dashboard</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="/config" class="menu-link text-theme fw-bold">
                    <div class="menu-icon"><i class="bi bi-sliders nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Configuration</div>
                </a>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ SIDEBAR -->
    <div id="sidebar" class="app-sidebar">
        <div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
            <div class="menu">
                <div class="menu-header">phpkaiharness</div>
                <div class="menu-item">
                    <a href="/" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">Dashboard</span>
                    </a>
                </div>
                <div class="menu-item active">
                    <a href="/config" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-sliders"></i></span>
                        <span class="menu-text">Configuration</span>
                    </a>
                </div>
                <div class="menu-divider"></div>
                <div class="menu-header">Categories</div>
                <div class="menu-item">
                    <a href="#general-pane" class="menu-link sidebar-tab-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">General</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#perf-pane" class="menu-link sidebar-tab-link">
                        <span class="menu-icon"><i class="bi bi-lightning-charge"></i></span>
                        <span class="menu-text">Performance & Context</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#security-pane" class="menu-link sidebar-tab-link">
                        <span class="menu-icon"><i class="bi bi-shield-lock"></i></span>
                        <span class="menu-text">Security & Guardrails</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#cognitive-pane" class="menu-link sidebar-tab-link">
                        <span class="menu-icon"><i class="bi bi-diagram-3"></i></span>
                        <span class="menu-text">Cognitive & Budgets</span>
                    </a>
                </div>
            </div>
            <div class="p-3 px-4 mt-auto">
                <div class="small text-inverse text-opacity-50 mb-1">Config file:</div>
                <div class="small text-inverse text-opacity-25 text-break" style="font-family:monospace;font-size:10px">
                    <?= htmlspecialchars(str_replace('\\', '/', $configPath ?: 'N/A')) ?>
                </div>
            </div>
        </div>
    </div>

    <button class="app-sidebar-mobile-backdrop"
        data-toggle-target=".app"
        data-toggle-class="app-sidebar-mobile-toggled"></button>

    <!-- ═══════════════════════════════════════════ CONTENT -->
    <div id="content" class="app-content">

        <?php if ($successMessage) { ?>
        <div class="alert alert-success alert-dismissible mb-4 fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php } ?>
        <?php if ($errorMessage) { ?>
        <div class="alert alert-danger alert-dismissible mb-4 fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php } ?>

        <form method="POST" action="/config">

        <!-- ── Nav Tabs ── -->
        <ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-pane" type="button" role="tab" aria-controls="general-pane" aria-selected="true">
                    <i class="bi bi-cpu me-2"></i>General
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="perf-tab" data-bs-toggle="tab" data-bs-target="#perf-pane" type="button" role="tab" aria-controls="perf-pane" aria-selected="false">
                    <i class="bi bi-lightning-charge me-2"></i>Performance & Context
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security-pane" type="button" role="tab" aria-controls="security-pane" aria-selected="false">
                    <i class="bi bi-shield-lock me-2"></i>Security & Guardrails
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cognitive-tab" data-bs-toggle="tab" data-bs-target="#cognitive-pane" type="button" role="tab" aria-controls="cognitive-pane" aria-selected="false">
                    <i class="bi bi-diagram-3 me-2"></i>Cognitive & Budgets
                </button>
            </li>
        </ul>

        <div class="tab-content" id="configTabContent">

            <!-- ── TAB 1: GENERAL ── -->
            <div class="tab-pane fade show active" id="general-pane" role="tabpanel" aria-labelledby="general-tab">
                <!-- Default LLM -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-4">
                            <span class="flex-grow-1"><i class="bi bi-stars text-theme me-2"></i>DEFAULT LLM</span>
                            <span class="text-inverse text-opacity-50 fw-normal">Fallback provider and model configuration</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Provider</label>
                                <input class="form-control form-control-sm" name="default_provider" value="<?= htmlspecialchars($config['default']['provider'] ?? 'ollama') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Model</label>
                                <input class="form-control form-control-sm" name="default_model" value="<?= htmlspecialchars($config['default']['model'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Max Iterations</label>
                                <input type="number" class="form-control form-control-sm" name="default_max_iterations" min="1" max="50" value="<?= htmlspecialchars($config['default']['max_iterations'] ?? 10) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Telemetry & Dashboard -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $telemetryOn = (bool) ($config['telemetry']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-bar-chart-line text-theme me-2"></i>TELEMETRY & DASHBOARD
                                    <?= $telemetryOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Registers telemetry API routes and monitors agent session loops</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="telemetry_enabled" value="1" <?= $telemetryOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Telemetry Route Prefix</label>
                                <input class="form-control form-control-sm" name="telemetry_route_prefix" value="<?= htmlspecialchars($config['telemetry']['route_prefix'] ?? 'harness') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>

            <!-- ── TAB 2: PERFORMANCE & CONTEXT ── -->
            <div class="tab-pane fade" id="perf-pane" role="tabpanel" aria-labelledby="perf-tab">
                <!-- Semantic Cache -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $cacheOn = (bool) ($config['cache']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-lightning-charge text-warning me-2"></i>SEMANTIC CACHING
                                    <?= $cacheOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Returns cached response for semantically similar prompts</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="cache_enabled" value="1" <?= $cacheOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Similarity Threshold (0.0 – 1.0)</label>
                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="1" name="cache_threshold" value="<?= htmlspecialchars($config['cache']['threshold'] ?? 0.88) ?>">
                                <div class="form-text text-inverse text-opacity-25">Higher values require a closer semantic match.</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Context Compaction -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-4">
                            <span class="flex-grow-1"><i class="bi bi-archive text-info me-2"></i>CONTEXT COMPACTION</span>
                            <span class="text-inverse text-opacity-50 fw-normal">Prunes oldest conversation history to fit context windows</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Strategy</label>
                                <select class="form-select form-select-sm" name="compaction_strategy">
                                    <?php foreach (['sliding_window', 'summarize', 'trim_oldest'] as $s) { ?>
                                    <option value="<?= $s ?>" <?= ($config['compaction']['strategy'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Max Conversation Turns</label>
                                <input type="number" class="form-control form-control-sm" name="compaction_max_turns" min="1" value="<?= htmlspecialchars($config['compaction']['max_turns'] ?? 6) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Max Token Threshold</label>
                                <input type="number" class="form-control form-control-sm" name="compaction_max_tokens_threshold" min="500" value="<?= htmlspecialchars($config['compaction']['max_tokens_threshold'] ?? 4000) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Context Compression -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $compressionOn = (bool) ($config['compaction']['compression']['enabled'] ?? $config['compression']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-file-earmark-zip text-success me-2"></i>CONTEXT COMPRESSION
                                    <?= $compressionOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Strips comments, extra whitespaces, and converts large files to method signatures</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="compression_enabled" value="1" <?= $compressionOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Line Compression Threshold</label>
                                <input type="number" class="form-control form-control-sm" name="compression_line_threshold" min="10" value="<?= htmlspecialchars($config['compaction']['compression']['line_threshold'] ?? $config['compression']['line_threshold'] ?? 150) ?>">
                                <div class="form-text text-inverse text-opacity-25">Attached code files exceeding this line limit are compressed.</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Model Prompt Optimizer -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $optimizerOn = (bool) ($config['optimizer']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-magic text-purple me-2"></i>MODEL PROMPT OPTIMIZER
                                    <?= $optimizerOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Symmetrically tunes system prompts for Qwen 3.5 & Gemma 4 architectures</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="optimizer_enabled" value="1" <?= $optimizerOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="small text-inverse text-opacity-50">
                            Detects model signatures (e.g. <code>qwen</code>, <code>gemma</code>) and injects formatting/reasoning requirements dynamically.
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>

            <!-- ── TAB 3: SECURITY & GUARDRAILS ── -->
            <div class="tab-pane fade" id="security-pane" role="tabpanel" aria-labelledby="security-tab">
                <!-- PII Masking -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $piiOn = (bool) ($config['pii_masking']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-shield-lock text-danger me-2"></i>PII MASKING
                                    <?= $piiOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Redacts sensitive patterns (emails, IPs, keys) before sending outbound LLM requests</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="pii_masking_enabled" value="1" <?= $piiOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="small text-inverse text-opacity-50">
                            Monitors prompt outputs and hides tokens using cryptographic placeholder values.
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Safety Guardrails -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $guardOn = (bool) ($config['guardrails']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-shield-check text-success me-2"></i>SAFETY GUARDRAILS
                                    <?= $guardOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Prevents high-risk tool executions and scopes tool authorization</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="guardrails_enabled" value="1" <?= $guardOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="small text-inverse text-opacity-50">
                            Ensures tools like <code>wsl_command</code> are gated and authorized inside scopes.
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Policy Guardrail Middleware -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $policyOn = (bool) ($config['policy_guardrail']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-person-lock text-warning me-2"></i>POLICY GUARDRAIL MIDDLEWARE
                                    <?= $policyOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Verifies Laravel Gate execution permissions for generated tool calls</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="policy_guardrail_enabled" value="1" <?= $policyOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="small text-inverse text-opacity-50">
                            Intercepts executions at the SDK layer and validates against <code>Gate::allows("execute-tool-{name}")</code>.
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Rate Limits -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $rateOn = (bool) ($config['rate_limiting']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-speedometer2 text-info me-2"></i>RATE LIMITING
                                    <?= $rateOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Throttles outbound LLM calls to avoid rate exhaustion</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="rate_limiting_enabled" value="1" <?= $rateOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Requests Per Minute (RPM)</label>
                                <input type="number" class="form-control form-control-sm" name="rate_limiting_rpm" min="1" value="<?= htmlspecialchars($config['rate_limiting']['requests_per_minute'] ?? 60) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Cooldown Period (ms)</label>
                                <input type="number" class="form-control form-control-sm" name="rate_limiting_cooldown_ms" min="0" value="<?= htmlspecialchars($config['rate_limiting']['cooldown_ms'] ?? 0) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- LLM Failover -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $failoverOn = (bool) ($config['failover']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-arrow-repeat text-info me-2"></i>LLM CLIENT FAILOVER
                                    <?= $failoverOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Automatically fails over to fallback LLM clients when primary provider errors out</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="failover_enabled" value="1" <?= $failoverOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>

            <!-- ── TAB 4: COGNITIVE & BUDGETS ── -->
            <div class="tab-pane fade" id="cognitive-pane" role="tabpanel" aria-labelledby="cognitive-tab">
                <!-- Environment Bootstrap -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $bootstrapOn = (bool) ($config['bootstrap']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-info-circle text-theme me-2"></i>ENVIRONMENT BOOTSTRAP MIDDLEWARE
                                    <?= $bootstrapOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Pre-injects system snapshot profiles (OS, PHP, Packages, Memory) before execution</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="bootstrap_enabled" value="1" <?= $bootstrapOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Thinking Budget -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $budgetOn = (bool) ($config['budget']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-stopwatch text-danger me-2"></i>THINKING BUDGET GATING
                                    <?= $budgetOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Monitors accumulated tokens and halts runaway loops safely</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="budget_enabled" value="1" <?= $budgetOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Max Accumulated Session Tokens</label>
                                <input type="number" class="form-control form-control-sm" name="budget_max_tokens" min="1000" value="<?= htmlspecialchars($config['budget']['max_tokens'] ?? 30000) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Cognitive Memory -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $cognitiveOn = (bool) ($config['cognitive_memory']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-brain text-info me-2"></i>COGNITIVE GRAPH MEMORY
                                    <?= $cognitiveOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Asynchronously distills session facts and stores them in cross-session memory table</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="cognitive_memory_enabled" value="1" <?= $cognitiveOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Draft Verification -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $draftOn = (bool) ($config['draft_verification']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-clipboard-check text-success me-2"></i>DRAFT VERIFICATION PIPELINE
                                    <?= $draftOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">Runs fast-draft generations followed by RAG-evidence verification passes</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="draft_verification_enabled" value="1" <?= $draftOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>

                <!-- Ontological Context Injector -->
                <div class="card mb-4">
                    <div class="card-body">
                        <?php $ontologyOn = (bool) ($config['ontology']['enabled'] ?? false); ?>
                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1">
                                <div class="fw-bold small d-flex align-items-center">
                                    <i class="bi bi-diagram-3 text-pink me-2"></i>ONTOLOGICAL CONTEXT INJECTOR
                                    <?= $ontologyOn ? '<span class="badge bg-success text-success bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[ACTIVE]</span>' : '<span class="badge bg-danger text-danger bg-opacity-15 ms-2" style="font-size:10px; padding: 2px 6px;">[DEACTIVATED]</span>' ?>
                                </div>
                                <div class="small text-inverse text-opacity-50 mt-1">RAG Context injection: queries pgvector/sqlite embeddings and prepends records to prompt</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="ontology_enabled" value="1" <?= $ontologyOn ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Embedding DB Column</label>
                                <input class="form-control form-control-sm" name="ontology_embedding_column" value="<?= htmlspecialchars($config['ontology']['embedding_column'] ?? 'embedding') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Cosine Similarity Threshold</label>
                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="1" name="ontology_similarity_threshold" value="<?= htmlspecialchars($config['ontology']['similarity_threshold'] ?? 0.30) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Max Records Injected</label>
                                <input type="number" class="form-control form-control-sm" name="ontology_max_records" min="1" max="10" value="<?= htmlspecialchars($config['ontology']['max_records'] ?? 3) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>

        </div>

        <!-- ── SAVE ── -->
        <div class="d-flex justify-content-end mb-4">
            <button type="submit" class="btn btn-theme px-5">
                <i class="bi bi-floppy-fill me-2"></i>Save Configuration
            </button>
        </div>

        </form>
    </div>
    <!-- END content -->

</div>
<!-- END #app -->

<!-- HUD Core JS -->
<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
    document.querySelectorAll('.sidebar-tab-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').substring(1);
            const tabEl = document.querySelector(`button[data-bs-target="#${targetId}"]`);
            if (tabEl) {
                const tab = new bootstrap.Tab(tabEl);
                tab.show();
            }
        });
    });
</script>
</body>
</html>
