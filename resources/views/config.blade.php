<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>phpkaiharness — Configuration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="phpkaiharness Configuration">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Core CSS -->
    <link href="{{ asset('vendor/harness/css/vendor.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/harness/css/app.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/harness/plugins/bootstrap-icons/font/bootstrap-icons.css') }}">
    <style>
        ::-webkit-scrollbar { width:5px; height:5px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.15); border-radius:4px; }

        /* ── Quantum Layered Config ── */
        .qcfg-layer {
            position: relative;
            margin-bottom: 20px;
        }
        .qcfg-layer-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            padding: 8px 14px;
            border-radius: 6px;
            background: rgba(255,255,255,0.03);
            border-left: 3px solid var(--layer-color, #00d2ff);
        }
        .qcfg-layer-icon {
            font-size: 1.1rem;
            color: var(--layer-color, #00d2ff);
        }
        .qcfg-layer-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--layer-color, #00d2ff);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .qcfg-layer-subtitle {
            font-size: 10px;
            color: rgba(255,255,255,0.35);
            margin-left: auto;
        }
        .qcfg-layer-count {
            font-size: 10px;
            color: rgba(255,255,255,0.3);
        }
        .qcfg-nodes-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            padding: 0 4px;
        }
        .qcfg-node {
            flex: 1 1 320px;
            min-width: 280px;
            max-width: 580px;
            background: rgba(18, 18, 28, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            padding: 16px;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 4px 10px rgba(0,0,0,0.4);
            position: relative;
            overflow: visible;
        }
        .qcfg-node::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: var(--node-color, #00d2ff);
            opacity: 0.6;
            border-radius: 6px 6px 0 0;
        }
        .qcfg-node .card-arrow {
            position: absolute;
            left: 0; right: 0; top: 0; bottom: 0;
            pointer-events: none;
        }
        .qcfg-node .card-arrow > div {
            position: absolute;
            width: 10px; height: 10px;
            border-color: rgba(255,255,255,0.06);
            border-style: solid; border-width: 0;
        }
        .qcfg-node .card-arrow-top-left { top:0; left:0; border-top-width:1px; border-left-width:1px; border-radius:6px 0 0 0; }
        .qcfg-node .card-arrow-top-right { top:0; right:0; border-top-width:1px; border-right-width:1px; border-radius:0 6px 0 0; }
        .qcfg-node .card-arrow-bottom-left { bottom:0; left:0; border-bottom-width:1px; border-left-width:1px; border-radius:0 0 0 6px; }
        .qcfg-node .card-arrow-bottom-right { bottom:0; right:0; border-bottom-width:1px; border-right-width:1px; border-radius:0 0 6px 0; }
        .qcfg-node:hover {
            border-color: var(--node-color, var(--bs-theme));
            box-shadow: 0 6px 20px rgba(0,0,0,0.5), 0 0 8px var(--node-color, var(--bs-theme));
        }
        .qcfg-node-header {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }
        .qcfg-node-icon {
            font-size: 1.4rem;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px; height: 36px;
            border-radius: 6px;
            background: rgba(255,255,255,0.04);
        }
        .qcfg-node-title {
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            flex-grow: 1;
        }
        .qcfg-node-key {
            font-size: 9px;
            color: rgba(255,255,255,0.3);
            font-family: monospace;
        }
        .qcfg-node-desc {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .qcfg-node-body {
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 12px;
        }
        .qcfg-connector {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px 0 8px 0;
            margin-left: 14px;
        }
        .qcfg-flow-v-line {
            stroke-dasharray: 5 3;
            animation: qcfg-flow-dash 1.5s linear infinite;
        }
        @keyframes qcfg-flow-dash {
            to { stroke-dashoffset: -16; }
        }
        .qcfg-flow-glow {
            animation: qcfg-flow-glow 2s ease-in-out infinite;
        }
        @keyframes qcfg-flow-glow {
            0%, 100% { opacity: 0.2; }
            50% { opacity: 0.6; }
        }
        .qcfg-flow-particle {
            animation: qcfg-flow-particle 3s linear infinite;
        }
        @keyframes qcfg-flow-particle {
            0% { cy: 0; opacity: 0; }
            10% { opacity: 0.8; }
            90% { opacity: 0.8; }
            100% { cy: 32; opacity: 0; }
        }
    </style>
</head>
<body>
<div id="app" class="app">

    <!-- HEADER -->
    <div id="header" class="app-header">
        <div class="desktop-toggler">
            <button type="button" class="menu-toggler" data-toggle-class="app-sidebar-collapsed" data-dismiss-class="app-sidebar-toggled" data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="mobile-toggler">
            <button type="button" class="menu-toggler" data-toggle-class="app-sidebar-mobile-toggled" data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <div class="brand">
            <a href="{{ route('harness.dashboard') }}" class="brand-logo">
                <span class="brand-img"><span class="brand-img-text text-theme">K</span></span>
                <span class="brand-text">phpkaiharness</span>
            </a>
        </div>
        <div class="menu">
            <div class="menu-item">
                <a href="{{ route('harness.dashboard') }}" class="menu-link">
                    <div class="menu-icon"><i class="bi bi-cpu nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Dashboard</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="{{ route('harness.config') }}" class="menu-link text-theme fw-bold">
                    <div class="menu-icon"><i class="bi bi-sliders nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Configuration</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="{{ url('/') }}" class="menu-link">
                    <div class="menu-icon"><i class="bi bi-house nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">App</div>
                </a>
            </div>
        </div>
    </div>

    <!-- SIDEBAR -->
    <div id="sidebar" class="app-sidebar">
        <div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
            <div class="menu">
                <div class="menu-header">phpkaiharness</div>
                <div class="menu-item">
                    <a href="{{ route('harness.dashboard') }}" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">Dashboard</span>
                    </a>
                </div>
                <div class="menu-item active">
                    <a href="{{ route('harness.config') }}" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-sliders"></i></span>
                        <span class="menu-text">Configuration</span>
                    </a>
                </div>
                <div class="menu-divider"></div>
                <div class="menu-header">Quantum Layers</div>
                <div class="menu-item">
                    <a href="#layer-general" class="menu-link sidebar-layer-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">General Layer</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#layer-preprocessing" class="menu-link sidebar-layer-link">
                        <span class="menu-icon"><i class="bi bi-funnel-fill"></i></span>
                        <span class="menu-text">Pre-Processing Layer</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#layer-agentloop" class="menu-link sidebar-layer-link">
                        <span class="menu-icon"><i class="bi bi-arrow-repeat"></i></span>
                        <span class="menu-text">Agent Loop Layer</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#layer-postprocessing" class="menu-link sidebar-layer-link">
                        <span class="menu-icon"><i class="bi bi-shield-check"></i></span>
                        <span class="menu-text">Post-Processing Layer</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#layer-quantum" class="menu-link sidebar-layer-link">
                        <span class="menu-icon"><i class="bi bi-atom"></i></span>
                        <span class="menu-text">Quantum Layer</span>
                    </a>
                </div>
            </div>
            <div class="p-3 px-4 mt-auto">
                <div class="small text-inverse text-opacity-50 mb-1">Config file:</div>
                <div class="small text-inverse text-opacity-25 text-break" style="font-family:monospace;font-size:10px">
                    config/harness.php
                </div>
            </div>
        </div>
    </div>

    <button class="app-sidebar-mobile-backdrop" data-toggle-target=".app" data-toggle-class="app-sidebar-mobile-toggled"></button>

    <!-- CONTENT -->
    <div id="content" class="app-content">

        @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif
        @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        @endif
        <form method="POST" action="{{ route('harness.config') }}">
        @csrf

        @php
            $fgNodes = $config['feature_graph']['nodes'] ?? [];
            $activeFgCount = collect($fgNodes)->filter(fn($n) => ($n['enabled'] ?? false))->count();
        @endphp

        <!-- ── LAYER 1: GENERAL ── -->
        <div class="qcfg-layer" id="layer-general" style="--layer-color: #00d2ff">
            <div class="qcfg-layer-header">
                <i class="bi bi-cpu qcfg-layer-icon"></i>
                <span class="qcfg-layer-title">General Layer</span>
                <span class="qcfg-layer-subtitle">Provider, model, telemetry & session isolation</span>
                <span class="qcfg-layer-count">3 nodes</span>
            </div>
            <div class="qcfg-nodes-row">
                <!-- Default LLM -->
                <div class="qcfg-node" style="--node-color: #00d2ff">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#00d2ff"><i class="bi bi-stars"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Default LLM</div>
                            <div class="qcfg-node-key">default.provider / model</div>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Primary LLM provider and model selection. All agent loops start here.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Provider</label>
                                <input class="form-control form-control-sm" name="default_provider" value="{{ $config['default']['provider'] ?? 'ollama' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Model</label>
                                <input class="form-control form-control-sm" name="default_model" value="{{ $config['default']['model'] ?? '' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-inverse text-opacity-50">Max Iterations</label>
                                <input type="number" class="form-control form-control-sm" name="default_max_iterations" min="1" max="50" value="{{ $config['default']['max_iterations'] ?? 10 }}">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Telemetry -->
                <div class="qcfg-node" style="--node-color: #00d2ff">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#00d2ff"><i class="bi bi-bar-chart-line"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Telemetry & Dashboard</div>
                            <div class="qcfg-node-key">telemetry.enabled</div>
                        </div>
                        @php $telemetryOn = (bool)($config['telemetry']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="telemetry_enabled" value="1" {{ $telemetryOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Registers telemetry API routes and monitors agent session loops.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Route Prefix</label>
                                <input class="form-control form-control-sm" name="telemetry_route_prefix" value="{{ $config['telemetry']['route_prefix'] ?? 'harness' }}">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                @if($telemetryOn)
                                    <span class="badge bg-success text-success bg-opacity-15" style="font-size:10px">[ACTIVE]</span>
                                @else
                                    <span class="badge bg-danger text-danger bg-opacity-15" style="font-size:10px">[DEACTIVATED]</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Session Isolation -->
                <div class="qcfg-node" style="--node-color: #00d2ff">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#00d2ff"><i class="bi bi-hdd-network"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Session Isolation</div>
                            <div class="qcfg-node-key">session_isolation.enabled</div>
                        </div>
                        @php $isoOn = (bool)($config['session_isolation']['enabled'] ?? true); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="session_isolation_enabled" value="1" {{ $isoOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Maps each Laravel PHP session to its own phpkaiharness folder with a dedicated SQLite monitor DB and quantum memory DB.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Base Path (optional override)</label>
                                <input class="form-control form-control-sm" name="session_isolation_base_path" value="{{ $config['session_isolation']['base_path'] ?? '' }}" placeholder="storage/app/phpkaiharness/sessions">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-inverse text-opacity-50">Cleanup Hours</label>
                                <input type="number" class="form-control form-control-sm" name="session_isolation_cleanup_hours" min="1" value="{{ $config['session_isolation']['cleanup_hours'] ?? 24 }}">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                @if($isoOn)
                                    <span class="badge bg-success text-success bg-opacity-15" style="font-size:10px">[ACTIVE]</span>
                                @else
                                    <span class="badge bg-danger text-danger bg-opacity-15" style="font-size:10px">[DEACTIVATED]</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- Inter-layer connector -->
        <div class="qcfg-connector">
            <svg width="40" height="32" viewBox="0 0 40 32">
                <line x1="20" y1="0" x2="20" y2="32" stroke="#00d2ff" stroke-width="1.5" opacity="0.4" class="qcfg-flow-v-line" />
                <line x1="20" y1="0" x2="20" y2="32" stroke="#00d2ff" stroke-width="3" opacity="0.1" class="qcfg-flow-glow" />
                <circle cx="20" cy="0" r="2.5" fill="#00d2ff" class="qcfg-flow-particle" />
            </svg>
        </div>

        <!-- ── LAYER 2: PRE-PROCESSING ── -->
        <div class="qcfg-layer" id="layer-preprocessing" style="--layer-color: #a855f7">
            <div class="qcfg-layer-header">
                <i class="bi bi-funnel-fill qcfg-layer-icon"></i>
                <span class="qcfg-layer-title">Pre-Processing Layer</span>
                <span class="qcfg-layer-subtitle">Feature graph nodes — pipeline stages</span>
                <span class="qcfg-layer-count">{{ $activeFgCount }} active / {{ count($fgNodes) }} total</span>
            </div>
            <div class="qcfg-nodes-row">
                @php
                    $fgDefaults = [
                        'draft_verification' => ['label' => 'Draft Verification', 'desc' => 'Fast-draft generation + RAG-evidence verification pass', 'icon' => 'bi-clipboard-check', 'color' => '#10b981', 'fields' => []],
                        'environment_bootstrap' => ['label' => 'Environment Bootstrap', 'desc' => 'Injects environment context and system prompts before agent execution', 'icon' => 'bi-bootstrap', 'color' => '#00d2ff', 'fields' => []],
                        'context_compression' => ['label' => 'Context Compression', 'desc' => 'Strips comments, whitespaces, and compresses large file attachments', 'icon' => 'bi-file-earmark-zip', 'color' => '#3b82f6', 'fields' => []],
                        'model_optimizer' => ['label' => 'Model Prompt Optimizer', 'desc' => 'Model-specific prompt tuning (Qwen/Gemma strategies)', 'icon' => 'bi-magic', 'color' => '#a855f7', 'fields' => [['type' => 'toggle', 'name' => 'optimizer_enabled', 'label' => 'Optimizer Enabled', 'config_key' => 'optimizer.enabled']]],
                        'ontology_injection' => ['label' => 'Ontology Context Injection', 'desc' => 'RAG context from host app domain models via embeddings', 'icon' => 'bi-diagram-3', 'color' => '#ec4899', 'fields' => [
                            ['type' => 'toggle', 'name' => 'ontology_enabled', 'label' => 'Ontology Enabled', 'config_key' => 'ontology.enabled'],
                            ['type' => 'text', 'name' => 'ontology_embedding_column', 'label' => 'Embedding DB Column', 'config_key' => 'ontology.embedding_column', 'value' => $config['ontology']['embedding_column'] ?? 'embedding'],
                            ['type' => 'number', 'name' => 'ontology_similarity_threshold', 'label' => 'Similarity Threshold (0.0–1.0)', 'config_key' => 'ontology.similarity_threshold', 'value' => $config['ontology']['similarity_threshold'] ?? 0.30, 'step' => '0.01', 'min' => '0', 'max' => '1'],
                            ['type' => 'number', 'name' => 'ontology_max_records', 'label' => 'Max Records Injected', 'config_key' => 'ontology.max_records', 'value' => $config['ontology']['max_records'] ?? 3, 'min' => '1', 'max' => '20'],
                            ['type' => 'toggle', 'name' => 'ontology_namespaces_enabled', 'label' => 'Namespaces Filtering', 'config_key' => 'ontology.namespaces.enabled', 'value' => $config['ontology']['namespaces']['enabled'] ?? true],
                        ]],
                        'semantic_cache' => ['label' => 'Semantic Cache', 'desc' => 'Returns cached responses for semantically similar prompts', 'icon' => 'bi-lightning-charge', 'color' => '#f59e0b', 'fields' => [
                            ['type' => 'toggle', 'name' => 'cache_enabled', 'label' => 'Cache Enabled', 'config_key' => 'cache.enabled'],
                            ['type' => 'number', 'name' => 'cache_threshold', 'label' => 'Similarity Threshold (0.0–1.0)', 'config_key' => 'cache.threshold', 'value' => $config['cache']['threshold'] ?? 0.88, 'step' => '0.01', 'min' => '0', 'max' => '1'],
                            ['type' => 'toggle', 'name' => 'cache_redis_enabled', 'label' => 'Redis L1 Cache Enabled', 'config_key' => 'cache.redis.enabled', 'value' => $config['cache']['redis']['enabled'] ?? true],
                            ['type' => 'text', 'name' => 'cache_redis_connection', 'label' => 'Redis Connection Name', 'config_key' => 'cache.redis.connection', 'value' => $config['cache']['redis']['connection'] ?? 'default'],
                            ['type' => 'select', 'name' => 'cache_redis_decay_mode', 'label' => 'Redis Decay Mode', 'config_key' => 'cache.redis.decay_mode', 'value' => $config['cache']['redis']['decay_mode'] ?? 'dissipative', 'options' => ['dissipative', 'step', 'none']],
                            ['type' => 'toggle', 'name' => 'cache_redis_subjective_field_enabled', 'label' => 'Subjective Field Enabled', 'config_key' => 'cache.redis.subjective_field.enabled', 'value' => $config['cache']['redis']['subjective_field']['enabled'] ?? true],
                            ['type' => 'number', 'name' => 'cache_redis_subjective_field_bias_weight', 'label' => 'Subjective Bias Weight (0.0–1.0)', 'config_key' => 'cache.redis.subjective_field.bias_weight', 'value' => $config['cache']['redis']['subjective_field']['bias_weight'] ?? 0.15, 'step' => '0.01', 'min' => '0', 'max' => '1'],
                            ['type' => 'toggle', 'name' => 'cache_redis_order_sensitive', 'label' => 'Order Sensitive', 'config_key' => 'cache.redis.order_sensitive', 'value' => $config['cache']['redis']['order_sensitive'] ?? true],
                            ['type' => 'toggle', 'name' => 'cache_verify_with_llm', 'label' => 'Verify Cache with LLM', 'config_key' => 'cache.verify_with_llm', 'value' => $config['cache']['verify_with_llm'] ?? true],
                        ]],
                        'context_compactor' => ['label' => 'Context Compactor', 'desc' => 'Prunes oldest conversation history to fit context windows', 'icon' => 'bi-archive', 'color' => '#06b6d4', 'fields' => [
                            ['type' => 'select', 'name' => 'compaction_strategy', 'label' => 'Strategy', 'config_key' => 'compaction.strategy', 'value' => $config['compaction']['strategy'] ?? 'sliding_window', 'options' => ['sliding_window', 'summarize', 'trim_oldest']],
                            ['type' => 'number', 'name' => 'compaction_max_turns', 'label' => 'Max Conversation Turns', 'config_key' => 'compaction.max_turns', 'value' => $config['compaction']['max_turns'] ?? 6, 'min' => '1'],
                            ['type' => 'number', 'name' => 'compaction_max_tokens_threshold', 'label' => 'Max Token Threshold', 'config_key' => 'compaction.max_tokens_threshold', 'value' => $config['compaction']['max_tokens_threshold'] ?? 4000, 'min' => '500'],
                        ]],
                        'guardrails' => ['label' => 'Safety Guardrails', 'desc' => 'Prevents high-risk tool executions and scopes authorization', 'icon' => 'bi-shield-check', 'color' => '#10b981', 'fields' => [['type' => 'toggle', 'name' => 'guardrails_enabled', 'label' => 'Guardrails Enabled', 'config_key' => 'guardrails.enabled']]],
                        'cognitive_memory' => ['label' => 'Cognitive Graph Memory', 'desc' => 'Cross-session fact distillation and memory storage (synchronous, inline)', 'icon' => 'bi-brain', 'color' => '#3b82f6', 'fields' => [
                            ['type' => 'toggle', 'name' => 'cognitive_memory_enabled', 'label' => 'Cognitive Memory Enabled', 'config_key' => 'cognitive_memory.enabled'],
                            ['type' => 'number', 'name' => 'cognitive_memory_max_depth', 'label' => 'Max Graph Depth', 'config_key' => 'cognitive_memory.max_depth', 'value' => $config['cognitive_memory']['max_depth'] ?? 3, 'min' => '1', 'max' => '10'],
                            ['type' => 'number', 'name' => 'cognitive_memory_coherence_threshold', 'label' => 'Coherence Threshold (0.0–1.0)', 'config_key' => 'cognitive_memory.coherence_threshold', 'value' => $config['cognitive_memory']['coherence_threshold'] ?? 0.15, 'step' => '0.01', 'min' => '0', 'max' => '1'],
                            ['type' => 'number', 'name' => 'cognitive_memory_decay_rate', 'label' => 'Memory Decay Rate (0.0–1.0)', 'config_key' => 'cognitive_memory.decay_rate', 'value' => $config['cognitive_memory']['decay_rate'] ?? 0.05, 'step' => '0.01', 'min' => '0', 'max' => '1'],
                        ]],
                        'quantum_harness' => ['label' => 'Quantum Memory Harness', 'desc' => 'Quantum-inspired semantic memory with entanglement traversal. Full configuration is in the Quantum Layer below.', 'icon' => 'bi-atom', 'color' => '#8b5cf6', 'fields' => []],
                    ];
                @endphp
                @foreach ($fgDefaults as $nodeKey => $nodeInfo)
                    @php $nodeOn = (bool)($fgNodes[$nodeKey]['enabled'] ?? false); @endphp
                    <div class="qcfg-node" style="--node-color: {{ $nodeInfo['color'] }}">
                        <div class="qcfg-node-header">
                            <div class="qcfg-node-icon" style="color: {{ $nodeInfo['color'] }}"><i class="bi {{ $nodeInfo['icon'] }}"></i></div>
                            <div class="flex-grow-1">
                                <div class="qcfg-node-title">{{ $nodeInfo['label'] }}</div>
                                <div class="qcfg-node-key">{{ $nodeKey }}</div>
                            </div>
                            <div class="form-check form-switch ms-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="fg_{{ $nodeKey }}" value="1" {{ $nodeOn ? 'checked' : '' }}>
                            </div>
                        </div>
                        <div class="qcfg-node-desc">{{ $nodeInfo['desc'] }}</div>
                        @if(!empty($nodeInfo['fields']))
                        <div class="qcfg-node-body">
                            @foreach ($nodeInfo['fields'] as $field)
                                @php
                                    $fieldVal = $field['value'] ?? data_get($config, $field['config_key'] ?? '');
                                    $fieldOn = is_bool($fieldVal) ? $fieldVal : (bool)$fieldVal;
                                @endphp
                                @if($field['type'] === 'toggle')
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="small text-inverse text-opacity-50 flex-grow-1">{{ $field['label'] }}</span>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" name="{{ $field['name'] }}" value="1" {{ $fieldOn ? 'checked' : '' }}>
                                        </div>
                                    </div>
                                @elseif($field['type'] === 'select')
                                    <div class="mb-2">
                                        <label class="form-label small text-inverse text-opacity-50">{{ $field['label'] }}</label>
                                        <select class="form-select form-select-sm" name="{{ $field['name'] }}">
                                            @foreach ($field['options'] as $opt)
                                                <option value="{{ $opt }}" {{ $fieldVal === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif($field['type'] === 'number')
                                    <div class="mb-2">
                                        <label class="form-label small text-inverse text-opacity-50">{{ $field['label'] }}</label>
                                        <input type="number" class="form-control form-control-sm" name="{{ $field['name'] }}" step="{{ $field['step'] ?? '1' }}" min="{{ $field['min'] ?? '' }}" max="{{ $field['max'] ?? '' }}" value="{{ $fieldVal }}">
                                    </div>
                                @elseif($field['type'] === 'text')
                                    <div class="mb-2">
                                        <label class="form-label small text-inverse text-opacity-50">{{ $field['label'] }}</label>
                                        <input class="form-control form-control-sm" name="{{ $field['name'] }}" value="{{ $fieldVal }}">
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        @endif
                        <div class="mt-2">
                            @if($nodeOn)
                                <span class="badge bg-success text-success bg-opacity-15" style="font-size:10px">[ACTIVE]</span>
                            @else
                                <span class="badge bg-danger text-danger bg-opacity-15" style="font-size:10px">[DEACTIVATED]</span>
                            @endif
                        </div>
                        <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Inter-layer connector -->
        <div class="qcfg-connector">
            <svg width="40" height="32" viewBox="0 0 40 32">
                <line x1="20" y1="0" x2="20" y2="32" stroke="#a855f7" stroke-width="1.5" opacity="0.4" class="qcfg-flow-v-line" />
                <line x1="20" y1="0" x2="20" y2="32" stroke="#a855f7" stroke-width="3" opacity="0.1" class="qcfg-flow-glow" />
                <circle cx="20" cy="0" r="2.5" fill="#a855f7" class="qcfg-flow-particle" />
            </svg>
        </div>

        <!-- ── LAYER 3: AGENT LOOP ── -->
        <div class="qcfg-layer" id="layer-agentloop" style="--layer-color: #3b82f6">
            <div class="qcfg-layer-header">
                <i class="bi bi-arrow-repeat qcfg-layer-icon"></i>
                <span class="qcfg-layer-title">Agent Loop Layer</span>
                <span class="qcfg-layer-subtitle">Compression, security & failover</span>
                <span class="qcfg-layer-count">5 nodes</span>
            </div>
            <div class="qcfg-nodes-row">
                <!-- Context Compression -->
                <div class="qcfg-node" style="--node-color: #3b82f6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#3b82f6"><i class="bi bi-file-earmark-zip"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Context Compression</div>
                            <div class="qcfg-node-key">compaction.compression.enabled</div>
                        </div>
                        @php $compressionOn = (bool)($config['compaction']['compression']['enabled'] ?? $config['compression']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="compression_enabled" value="1" {{ $compressionOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Strips comments, extra whitespaces, and converts large files to method signatures.</div>
                    <div class="qcfg-node-body">
                        <label class="form-label small text-inverse text-opacity-50">Line Compression Threshold</label>
                        <input type="number" class="form-control form-control-sm" name="compression_line_threshold" min="10" value="{{ $config['compaction']['compression']['line_threshold'] ?? $config['compression']['line_threshold'] ?? 150 }}">
                        <div class="form-text text-inverse text-opacity-25">Attached code files exceeding this line limit are compressed.</div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- PII Masking -->
                <div class="qcfg-node" style="--node-color: #3b82f6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#3b82f6"><i class="bi bi-shield-lock"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">PII Masking</div>
                            <div class="qcfg-node-key">pii_masking.enabled</div>
                        </div>
                        @php $piiOn = (bool)($config['pii_masking']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="pii_masking_enabled" value="1" {{ $piiOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Redacts sensitive patterns (emails, IPs, keys) before sending outbound LLM requests.</div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Rate Limiting -->
                <div class="qcfg-node" style="--node-color: #3b82f6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#3b82f6"><i class="bi bi-speedometer2"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Rate Limiting</div>
                            <div class="qcfg-node-key">rate_limiting.enabled</div>
                        </div>
                        @php $rateOn = (bool)($config['rate_limiting']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="rate_limiting_enabled" value="1" {{ $rateOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Throttles outbound LLM calls to avoid rate exhaustion.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Requests Per Minute</label>
                                <input type="number" class="form-control form-control-sm" name="rate_limiting_rpm" min="1" value="{{ $config['rate_limiting']['requests_per_minute'] ?? 60 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Cooldown (ms)</label>
                                <input type="number" class="form-control form-control-sm" name="rate_limiting_cooldown_ms" min="0" value="{{ $config['rate_limiting']['cooldown_ms'] ?? 0 }}">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Policy Guardrail -->
                <div class="qcfg-node" style="--node-color: #3b82f6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#3b82f6"><i class="bi bi-person-lock"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Policy Guardrail Middleware</div>
                            <div class="qcfg-node-key">policy_guardrail.enabled</div>
                        </div>
                        @php $policyOn = (bool)($config['policy_guardrail']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="policy_guardrail_enabled" value="1" {{ $policyOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Verifies Laravel Gate execution permissions for generated tool calls.</div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- LLM Failover -->
                <div class="qcfg-node" style="--node-color: #3b82f6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#3b82f6"><i class="bi bi-arrow-repeat"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">LLM Client Failover</div>
                            <div class="qcfg-node-key">failover.enabled</div>
                        </div>
                        @php $failoverOn = (bool)($config['failover']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="failover_enabled" value="1" {{ $failoverOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Automatically fails over to fallback LLM clients when primary provider errors out.</div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- Inter-layer connector -->
        <div class="qcfg-connector">
            <svg width="40" height="32" viewBox="0 0 40 32">
                <line x1="20" y1="0" x2="20" y2="32" stroke="#3b82f6" stroke-width="1.5" opacity="0.4" class="qcfg-flow-v-line" />
                <line x1="20" y1="0" x2="20" y2="32" stroke="#3b82f6" stroke-width="3" opacity="0.1" class="qcfg-flow-glow" />
                <circle cx="20" cy="0" r="2.5" fill="#3b82f6" class="qcfg-flow-particle" />
            </svg>
        </div>

        <!-- ── LAYER 4: POST-PROCESSING ── -->
        <div class="qcfg-layer" id="layer-postprocessing" style="--layer-color: #f97316">
            <div class="qcfg-layer-header">
                <i class="bi bi-shield-check qcfg-layer-icon"></i>
                <span class="qcfg-layer-title">Post-Processing Layer</span>
                <span class="qcfg-layer-subtitle">Budget, bootstrap & draft verification</span>
                <span class="qcfg-layer-count">3 nodes</span>
            </div>
            <div class="qcfg-nodes-row">
                <!-- Thinking Budget -->
                <div class="qcfg-node" style="--node-color: #f97316">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#f97316"><i class="bi bi-stopwatch"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Thinking Budget Gating</div>
                            <div class="qcfg-node-key">budget.enabled</div>
                        </div>
                        @php $budgetOn = (bool)($config['budget']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="budget_enabled" value="1" {{ $budgetOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Monitors accumulated tokens and halts runaway loops safely.</div>
                    <div class="qcfg-node-body">
                        <label class="form-label small text-inverse text-opacity-50">Max Accumulated Session Tokens</label>
                        <input type="number" class="form-control form-control-sm" name="budget_max_tokens" min="1000" value="{{ $config['budget']['max_tokens'] ?? 30000 }}">
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Environment Bootstrap -->
                <div class="qcfg-node" style="--node-color: #f97316">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#f97316"><i class="bi bi-info-circle"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Environment Bootstrap</div>
                            <div class="qcfg-node-key">bootstrap.enabled</div>
                        </div>
                        @php $bootstrapOn = (bool)($config['bootstrap']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="bootstrap_enabled" value="1" {{ $bootstrapOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Pre-injects system snapshot profiles (OS, PHP, Packages, Memory) before execution.</div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Draft Verification -->
                <div class="qcfg-node" style="--node-color: #f97316">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#f97316"><i class="bi bi-clipboard-check"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Draft Verification Pipeline</div>
                            <div class="qcfg-node-key">draft_verification.enabled</div>
                        </div>
                        @php $draftOn = (bool)($config['draft_verification']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="draft_verification_enabled" value="1" {{ $draftOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Runs fast-draft generations followed by RAG-evidence verification passes.</div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- Inter-layer connector -->
        <div class="qcfg-connector">
            <svg width="40" height="32" viewBox="0 0 40 32">
                <line x1="20" y1="0" x2="20" y2="32" stroke="#f97316" stroke-width="1.5" opacity="0.4" class="qcfg-flow-v-line" />
                <line x1="20" y1="0" x2="20" y2="32" stroke="#f97316" stroke-width="3" opacity="0.1" class="qcfg-flow-glow" />
                <circle cx="20" cy="0" r="2.5" fill="#f97316" class="qcfg-flow-particle" />
            </svg>
        </div>

        <!-- ── LAYER 5: QUANTUM ── -->
        <div class="qcfg-layer" id="layer-quantum" style="--layer-color: #8b5cf6">
            <div class="qcfg-layer-header">
                <i class="bi bi-atom qcfg-layer-icon"></i>
                <span class="qcfg-layer-title">Quantum Layer</span>
                <span class="qcfg-layer-subtitle">Quantum memory & Qwen Cloud harness</span>
                <span class="qcfg-layer-count">2 nodes</span>
            </div>
            <div class="qcfg-nodes-row">
                <!-- Quantum Harness -->
                <div class="qcfg-node" style="--node-color: #8b5cf6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#8b5cf6"><i class="bi bi-atom"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Quantum-Inspired Memory Harness</div>
                            <div class="qcfg-node-key">quantum_harness.enabled</div>
                        </div>
                        @php $quantumOn = (bool)($config['quantum_harness']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="quantum_harness_enabled" value="1" {{ $quantumOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Integrates quantum state nodes, phase interference, and semantic entanglement for RAG-based memory retrieval. Works with any LLM provider.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Alpha (cosine weight)</label>
                                <input type="number" class="form-control form-control-sm" step="0.05" min="0" max="1" name="quantum_alpha" value="{{ $config['quantum_harness']['alpha'] ?? 0.7 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Beta (interference weight)</label>
                                <input type="number" class="form-control form-control-sm" step="0.05" min="0" max="1" name="quantum_beta" value="{{ $config['quantum_harness']['beta'] ?? 0.3 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Similarity Threshold</label>
                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="1" name="quantum_similarity_threshold" value="{{ $config['quantum_harness']['similarity_threshold'] ?? 0.30 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Max Anchors</label>
                                <input type="number" class="form-control form-control-sm" min="1" max="20" name="quantum_max_anchors" value="{{ $config['quantum_harness']['max_anchors'] ?? 3 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Coherence Decay Rate</label>
                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="1" name="quantum_coherence_decay" value="{{ $config['quantum_harness']['coherence_decay'] ?? 0.05 }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Density Matrix Bias</label>
                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="1" name="quantum_density_matrix_bias" value="{{ $config['quantum_harness']['density_matrix_bias'] ?? 0.10 }}">
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
                <!-- Qwen Cloud Harness -->
                <div class="qcfg-node" style="--node-color: #8b5cf6">
                    <div class="qcfg-node-header">
                        <div class="qcfg-node-icon" style="color:#8b5cf6"><i class="bi bi-cloud"></i></div>
                        <div class="flex-grow-1">
                            <div class="qcfg-node-title">Qwen Cloud Provider</div>
                            <div class="qcfg-node-key">qwen_provider</div>
                        </div>
                        @php $qwenOn = (bool)($config['qwen_provider']['enabled'] ?? $config['qwen_harness']['enabled'] ?? false); @endphp
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="qwen_provider_enabled" value="1" {{ $qwenOn ? 'checked' : '' }}>
                        </div>
                    </div>
                    <div class="qcfg-node-desc">Custom Qwen Cloud layer for structured output mode and response token limiting. Credentials are read from the host app's System Settings (AI Provider = Qwen) first, then from harness config, then env vars.</div>
                    <div class="qcfg-node-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">API Key (fallback — main app takes priority)</label>
                                <input type="password" class="form-control form-control-sm" name="qwen_provider_api_key" value="{{ $config['qwen_provider']['api_key'] ?? '' }}" placeholder="sk-...">
                                <div class="form-text text-inverse text-opacity-25">Set in System Settings &rarr; AI Provider for production.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Base URL</label>
                                <input type="text" class="form-control form-control-sm" name="qwen_provider_url" value="{{ $config['qwen_provider']['url'] ?? 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1' }}" placeholder="https://dashscope-intl.aliyuncs.com/compatible-mode/v1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Default Model</label>
                                <input type="text" class="form-control form-control-sm" name="qwen_provider_model" value="{{ $config['qwen_provider']['model'] ?? 'qwen-plus' }}" placeholder="qwen-plus">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Light Model (multi-agent)</label>
                                <input type="text" class="form-control form-control-sm" name="qwen_provider_light_model" value="{{ $config['qwen_provider']['light_model'] ?? 'qwen-turbo' }}" placeholder="qwen-turbo">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Structured Output Mode</label>
                                <select class="form-select form-select-sm" name="qwen_provider_structured_output">
                                    @foreach (['json_object', 'json_schema', 'none'] as $mode)
                                    <option value="{{ $mode }}" {{ ($config['qwen_provider']['structured_output'] ?? $config['qwen_harness']['structured_output'] ?? 'json_object') === $mode ? 'selected' : '' }}>{{ $mode }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Max Response Tokens</label>
                                <input type="number" class="form-control form-control-sm" name="qwen_provider_max_tokens" min="256" max="32768" value="{{ $config['qwen_provider']['max_tokens'] ?? $config['qwen_harness']['max_tokens'] ?? 4096 }}">
                                <div class="form-text text-inverse text-opacity-25">Caps response generation to prevent timeouts.</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- ── SAVE ── -->
        <div class="d-flex justify-content-end mb-4 mt-3">
            <button type="submit" class="btn btn-theme px-5">
                <i class="bi bi-floppy-fill me-2"></i>Save Configuration
            </button>
        </div>

        </form>

    </div>
</div>

<script src="{{ asset('vendor/harness/js/vendor.min.js') }}"></script>
<script src="{{ asset('vendor/harness/js/app.min.js') }}"></script>
<script>
    document.querySelectorAll('.sidebar-layer-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').substring(1);
            const targetEl = document.getElementById(targetId);
            if (targetEl) {
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
</script>
</body>
</html>
