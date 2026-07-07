<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>phpkaiharness — Telemetry Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="phpkaiharness AI Agent Telemetry Dashboard">
    <!-- Core CSS -->
    <link href="{{ asset('vendor/harness/css/vendor.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/harness/css/app.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/harness/plugins/bootstrap-icons/font/bootstrap-icons.css') }}">
    <link href="{{ asset('vendor/harness/plugins/datatables.net-bs5/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/harness/plugins/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css') }}" rel="stylesheet">
    <style>
        .harness-trace-panel { min-height:250px; background:rgba(0,0,0,.25); border-radius:6px; padding:10px; }
        .trace-label { color:var(--bs-theme); font-weight:bold; margin-bottom:.15rem; }
        .collapsed { display:none !important; }
        .badge-executor   { background:rgba(66,135,245,.2); color:#82b4ff; }
        .badge-fast-path  { background:rgba(52,199,89,.2);  color:#5edb8e; }
        .badge-cache      { background:rgba(255,179,0,.2);  color:#ffd055; }
        .run-output-box { background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.06); border-radius:6px; padding:1rem; font-family:monospace; font-size:.82rem; white-space:pre-wrap; min-height:50px; display:none; }
        .run-output-box.visible { display:block; }
        ::-webkit-scrollbar { width:5px; height:5px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.15); border-radius:4px; }

        /* ── Quantum Layered Graph ── */
        .qgraph-wrapper {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 15px;
            overflow-x: auto;
        }
        .qgraph-layer {
            position: relative;
            margin-bottom: 0;
        }
        .qgraph-layer-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding: 4px 10px;
            border-radius: 4px;
            background: rgba(255,255,255,0.03);
            border-left: 3px solid var(--layer-color, #00d2ff);
        }
        .qgraph-layer-icon {
            font-size: 1rem;
            color: var(--layer-color, #00d2ff);
        }
        .qgraph-layer-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--layer-color, #00d2ff);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .qgraph-layer-subtitle {
            font-size: 9px;
            color: rgba(255,255,255,0.35);
            margin-left: auto;
        }
        .qgraph-layer-count {
            font-size: 9px;
            color: rgba(255,255,255,0.3);
        }
        .qgraph-nodes-row {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
            gap: 0;
            padding: 0 4px 14px 4px;
        }
        /* ── Node card — matches main .card design ── */
        .qgraph-node {
            flex: 0 0 150px;
            background: rgba(18, 18, 28, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 4px 10px rgba(0,0,0,0.4);
            position: relative;
            overflow: visible;
            margin-right: 0;
        }
        .qgraph-node::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: var(--node-color, #00d2ff);
            opacity: 0.6;
            border-radius: 6px 6px 0 0;
        }
        /* Card-arrow corners — same as main UI cards */
        .qgraph-node .card-arrow {
            position: absolute;
            left: 0; right: 0; top: 0; bottom: 0;
            pointer-events: none;
        }
        .qgraph-node .card-arrow > div {
            position: absolute;
            width: 10px;
            height: 10px;
            border-color: rgba(255,255,255,0.06);
            border-style: solid;
            border-width: 0;
        }
        .qgraph-node .card-arrow-top-left { top: 0; left: 0; border-top-width: 1px; border-left-width: 1px; border-radius: 6px 0 0 0; }
        .qgraph-node .card-arrow-top-right { top: 0; right: 0; border-top-width: 1px; border-right-width: 1px; border-radius: 0 6px 0 0; }
        .qgraph-node .card-arrow-bottom-left { bottom: 0; left: 0; border-bottom-width: 1px; border-left-width: 1px; border-radius: 0 0 0 6px; }
        .qgraph-node .card-arrow-bottom-right { bottom: 0; right: 0; border-bottom-width: 1px; border-right-width: 1px; border-radius: 0 0 6px 0; }
        .qgraph-node.disabled-node {
            border-color: rgba(239, 68, 68, 0.25) !important;
            background: rgba(30, 15, 15, 0.35) !important;
            opacity: 0.55;
        }
        .qgraph-node.disabled-node::before {
            background: #ef4444;
            opacity: 0.3;
        }
        .qgraph-node.disabled-node .qgraph-node-icon {
            color: #ef4444 !important;
        }
        .qgraph-node.disabled-node:hover {
            opacity: 0.85;
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.4) !important;
        }
        .qgraph-node.idle-node {
            border-color: rgba(16, 185, 129, 0.2) !important;
            background: rgba(15, 30, 20, 0.25) !important;
            opacity: 0.7;
        }
        .qgraph-node.idle-node::before {
            background: #10b981;
            opacity: 0.3;
        }
        .qgraph-node.idle-node .qgraph-node-icon {
            color: rgba(16, 185, 129, 0.5) !important;
        }
        .qgraph-node.idle-node:hover {
            opacity: 0.95;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.3) !important;
        }
        .qgraph-node:hover {
            transform: translateY(-3px);
            border-color: var(--node-color, var(--bs-theme));
            box-shadow: 0 6px 20px rgba(0,0,0,0.6), 0 0 10px var(--node-color, var(--bs-theme));
        }
        .qgraph-node.active {
            border-color: var(--node-color, var(--bs-theme)) !important;
            box-shadow: 0 0 14px var(--node-color, var(--bs-theme));
            background: rgba(25, 25, 38, 0.95);
        }
        .qgraph-node.active::before {
            opacity: 1;
            height: 3px;
        }
        .qgraph-node-icon {
            font-size: 1.2rem;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qgraph-node-title {
            font-size: 10px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 2px;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
        }
        .qgraph-node-meta {
            font-size: 8px;
            color: rgba(255,255,255,0.4);
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
        }
        .qgraph-node-metrics {
            margin-top: 5px;
            padding-top: 4px;
            border-top: 1px solid rgba(255,255,255,0.05);
            display: flex;
            justify-content: space-between;
            font-size: 8px;
        }
        .qgraph-node-metrics span:first-child {
            color: rgba(255,255,255,0.35);
        }
        .qgraph-node-status {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            z-index: 2;
        }
        .qgraph-node-status.on { background: #10b981; box-shadow: 0 0 4px #10b981; }
        .qgraph-node-status.off { background: #ef4444; opacity: 0.5; }
        .qgraph-node-status.idle { background: #f59e0b; opacity: 0.5; }

        /* ── Animated flow lines ── */
        .qgraph-flow-h {
            flex: 0 0 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            align-self: stretch;
        }
        .qgraph-flow-h svg, .qgraph-flow-v svg {
            overflow: visible;
        }
        .qgraph-flow-line {
            stroke-dasharray: 6 4;
            animation: flow-dash 1.2s linear infinite;
        }
        @keyframes flow-dash {
            to { stroke-dashoffset: -20; }
        }
        .qgraph-flow-glow {
            animation: flow-glow 2s ease-in-out infinite;
        }
        @keyframes flow-glow {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 0.9; }
        }
        .qgraph-flow-particle {
            animation: flow-particle-h 2.5s linear infinite;
        }
        @keyframes flow-particle-h {
            0% { cx: 0; opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { cx: 32; opacity: 0; }
        }
        .qgraph-connector {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2px 0 6px 0;
            margin-left: 14px;
        }
        .qgraph-connector-svg {
            overflow: visible;
        }
        .qgraph-flow-v-line {
            stroke-dasharray: 5 3;
            animation: flow-dash-v 1.5s linear infinite;
        }
        @keyframes flow-dash-v {
            to { stroke-dashoffset: -16; }
        }
        .qgraph-flow-v-particle {
            animation: flow-particle-v 3s linear infinite;
        }
        @keyframes flow-particle-v {
            0% { cy: 0; opacity: 0; }
            10% { opacity: 0.9; }
            90% { opacity: 0.9; }
            100% { cy: 32; opacity: 0; }
        }
        .qgraph-summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            padding: 8px 10px;
            margin-bottom: 12px;
            background: rgba(255,255,255,0.02);
            border-radius: 4px;
            font-size: 10px;
        }
        .qgraph-summary-item {
            display: flex;
            align-items: center;
            gap: 4px;
            color: rgba(255,255,255,0.5);
        }
        .qgraph-summary-dot {
            width: 7px; height: 7px; border-radius: 50%;
        }
        .workflow-details-box {
            position: relative;
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
                <a href="{{ route('harness.dashboard') }}" class="menu-link text-theme fw-bold">
                    <div class="menu-icon"><i class="bi bi-cpu nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Dashboard</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="{{ route('harness.config') }}" class="menu-link">
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
                <div class="menu-item active">
                    <a href="{{ route('harness.dashboard') }}" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">Dashboard</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="{{ route('harness.config') }}" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-sliders"></i></span>
                        <span class="menu-text">Configuration</span>
                    </a>
                </div>
                <div class="menu-divider"></div>
                <div class="menu-header">Telemetry</div>
                <div class="menu-item"><a href="#stats" class="menu-link"><span class="menu-icon"><i class="bi bi-bar-chart-line"></i></span><span class="menu-text">Statistics</span></a></div>
                <div class="menu-item"><a href="#sessions" class="menu-link"><span class="menu-icon"><i class="bi bi-list-ul"></i></span><span class="menu-text">Sessions</span></a></div>
                <div class="menu-item"><a href="#trace" class="menu-link"><span class="menu-icon"><i class="bi bi-diagram-3"></i></span><span class="menu-text">Trace Viewer</span></a></div>
                <div class="menu-divider"></div>
                <div class="menu-header">Isolation</div>
                <div class="menu-item"><a href="#session-manager" class="menu-link"><span class="menu-icon"><i class="bi bi-hdd-network"></i></span><span class="menu-text">Session Manager</span></a></div>
                <div class="menu-divider"></div>
                <div class="menu-header">Agent</div>
                <div class="menu-item"><a href="#run-agent" class="menu-link"><span class="menu-icon"><i class="bi bi-play-circle"></i></span><span class="menu-text">Run Agent</span></a></div>
            </div>
        </div>
    </div>
    <button class="app-sidebar-mobile-backdrop" data-toggle-target=".app" data-toggle-class="app-sidebar-mobile-toggled"></button>

    <!-- CONTENT -->
    <div id="content" class="app-content">

        @if(empty($sessions))
        <!-- Empty State -->
        <div class="row justify-content-center mt-5">
            <div class="col-xl-6 text-center">
                <div class="card">
                    <div class="card-body py-5">
                        <i class="bi bi-cpu display-3 text-theme opacity-50 mb-3 d-block"></i>
                        <h4 class="text-inverse mb-2">No Sessions Logged Yet</h4>
                        <p class="text-inverse text-opacity-50 mb-4">Execute the harness CLI to generate telemetry traces.</p>
                        <code class="d-inline-block bg-black bg-opacity-50 rounded px-4 py-2 text-theme fs-12px">
                            php bin/harness run "Check DNS records for google.com"
                        </code>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>
        @else

        <!-- STAT CARDS -->
        <div id="stats" class="row mb-3">
            @php
                $statCards = [
                    ['label'=>'Total Sessions',    'value'=> number_format($stats['total_sessions'] ?? 0),        'icon'=>'bi-cpu'],
                    ['label'=>'LLM Calls',         'value'=> number_format($stats['total_llm_calls'] ?? 0),        'icon'=>'bi-stars'],
                    ['label'=>'Tool Executions',   'value'=> number_format($stats['total_tool_calls'] ?? 0),       'icon'=>'bi-tools'],
                    ['label'=>'Avg Duration',      'value'=> number_format($stats['avg_duration_ms'] ?? 0).'ms',   'icon'=>'bi-stopwatch'],
                    ['label'=>'Prompt Tokens',     'value'=> number_format($stats['total_prompt_tokens'] ?? 0),    'icon'=>'bi-file-text'],
                    ['label'=>'Completion Tokens', 'value'=> number_format($stats['total_completion_tokens'] ?? 0),'icon'=>'bi-chat-square-text'],
                ];
            @endphp
            @foreach($statCards as $sc)
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3">
                            <span class="flex-grow-1 text-uppercase text-inverse text-opacity-50">{{ $sc['label'] }}</span>
                            <i class="bi {{ $sc['icon'] }} text-theme"></i>
                        </div>
                        <h3 class="mb-0">{{ $sc['value'] }}</h3>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
            @endforeach
        </div>

        <!-- SESSIONS + TRACE VIEWER -->
        <div class="row mb-3">
        <!-- FILTERS PANEL -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <span class="flex-grow-1 fw-bold small"><i class="bi bi-funnel text-theme me-2"></i>SESSION FILTERS</span>
                    <button class="btn btn-xs btn-outline-theme" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse" aria-expanded="true">
                        <i class="bi bi-sliders"></i> Toggle Filters Panel
                    </button>
                </div>
                <div class="collapse show" id="filtersCollapse">
                    <div class="row g-3">
                        <div class="col-xl-3 col-md-6">
                            <label class="form-label small text-inverse text-opacity-50">Method</label>
                            <select class="form-select form-select-sm" id="filterMethod" onchange="applyFilters()">
                                <option value="">All Methods</option>
                                <option value="executor-loop">executor-loop</option>
                                <option value="fast-path-keyword">fast-path-keyword</option>
                                <option value="semantic-cache">semantic-cache</option>
                            </select>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <label class="form-label small text-inverse text-opacity-50">Status</label>
                            <select class="form-select form-select-sm" id="filterStatus" onchange="applyFilters()">
                                <option value="">All Statuses</option>
                                <option value="completed">completed</option>
                                <option value="running">running</option>
                                <option value="failed">failed</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-md-4">
                            <label class="form-label small text-inverse text-opacity-50">Min LLM Calls</label>
                            <input type="number" class="form-control form-control-sm" id="filterMinLlm" min="0" placeholder="0" oninput="applyFilters()">
                        </div>
                        <div class="col-xl-2 col-md-4">
                            <label class="form-label small text-inverse text-opacity-50">Min Tool Calls</label>
                            <input type="number" class="form-control form-control-sm" id="filterMinTool" min="0" placeholder="0" oninput="applyFilters()">
                        </div>
                        <div class="col-xl-2 col-md-4 d-flex align-items-end">
                            <button class="btn btn-sm btn-outline-danger w-100" id="btnClearFilters" onclick="clearFilters()">
                                <i class="bi bi-x-circle me-1"></i> Clear Filters
                            </button>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3" id="activeFiltersContainer">
                        <!-- Active filter badges will be rendered here dynamically -->
                    </div>
                </div>
            </div>
            <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
        </div>

        <div class="row mb-3">
            <!-- MAIN SESSIONS -->
            <div class="col-xl-6 mb-3" id="mainSessionsCol">
                <div class="card mb-3 h-100">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3 align-items-center">
                            <span class="flex-grow-1"><i class="bi bi-hdd-network-fill text-theme me-2"></i>MAIN SESSIONS (PHP BROWSER SESSIONS)</span>
                            <span class="badge bg-theme text-black" id="mainSessionsCount">0</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" id="mainSessionTable" style="width:100%">
                                <thead>
                                    <tr class="text-inverse text-opacity-50 fw-bold small">
                                        <th>PHP Session ID</th><th>Runs</th><th>Duration</th><th>LLM</th><th>Tools</th><th>Tokens</th><th>Last Active</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>

            <!-- SUB SESSIONS -->
            <div class="col-xl-6 mb-3" id="subSessionsCol">
                <div class="card mb-3 h-100">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3 align-items-center">
                            <span class="flex-grow-1"><i class="bi bi-cpu-fill text-info me-2"></i>SUB-SESSIONS (AI INTERACTION RUNS)</span>
                            <span class="badge bg-info text-black" id="subSessionsCount">0</span>
                        </div>
                        <div id="subSessionsPlaceholder" class="text-center py-5 text-inverse text-opacity-50">
                            <i class="bi bi-arrow-left-right display-4 mb-3 opacity-25 d-block"></i>
                            <p class="mb-0 small">Select a main PHP session from the left table<br>to view its sub-sessions</p>
                        </div>
                        <div class="table-responsive d-none" id="subSessionsTableWrapper">
                            <table class="table table-sm table-hover align-middle mb-0" id="subSessionTable" style="width:100%">
                                <thead>
                                    <tr class="text-inverse text-opacity-50 fw-bold small">
                                        <th>Sub-Session ID</th><th>Method</th><th style="max-width:180px">Prompt</th><th>Duration</th><th>LLM</th><th>Tools</th><th>Status</th><th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>
            <div class="col-12" id="trace">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3">
                            <span class="flex-grow-1">EXECUTION TRACE VIEWER</span>
                            <span class="badge bg-theme text-black" id="traceMethodBadge" style="display:none"></span>
                        </div>
                        <div class="harness-trace-panel" id="tracePanel">
                            <div id="traceEmpty" class="d-flex flex-column align-items-center justify-content-center py-5 text-inverse text-opacity-50">
                                <i class="bi bi-diagram-3 display-4 mb-3 opacity-25"></i>
                                <p class="mb-0 text-center small">Select any session to view its<br>detailed execution trace</p>
                            </div>
                            <div class="collapsed" id="traceContent">
                                <div class="d-flex justify-content-between align-items-center border-bottom border-inverse border-opacity-15 pb-2 mb-3">
                                    <div>
                                        <div class="fw-bold text-inverse small" id="traceSessionId">—</div>
                                        <div class="fs-10px text-inverse text-opacity-50" id="traceSessionTime">—</div>
                                        <div id="traceParentBadge" style="display:none;margin-top:4px">
                                            <span class="badge fs-10px" style="background:rgba(99,102,241,.15);color:#a5b4fc;border:1px solid rgba(99,102,241,.3)">
                                                <i class="bi bi-diagram-2 me-1"></i>
                                                <span id="traceParentLabel"></span>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <span id="configDriftBadge" style="display:none;cursor:pointer" onclick="toggleConfigDriftPanel()">
                                            <span class="badge" style="background:rgba(234,179,8,.15);color:#fbbf24;border:1px solid rgba(234,179,8,.3);font-size:11px">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Config Drift
                                            </span>
                                        </span>
                                        <span id="configSyncBadge" style="display:none">
                                            <span class="badge" style="background:rgba(34,197,94,.1);color:#4ade80;border:1px solid rgba(34,197,94,.2);font-size:11px">
                                                <i class="bi bi-check-circle-fill me-1"></i>Config In Sync
                                            </span>
                                        </span>
                                        <button class="btn btn-sm btn-outline-theme text-black" id="debugReportBtn" onclick="downloadDebugReport()" style="display:none;">
                                            <i class="bi bi-bug-fill me-1"></i>Debug Report
                                        </button>
                                    </div>
                                </div>
                                <!-- Config Drift Panel -->
                                <div id="configDriftPanel" style="display:none;margin-bottom:12px">
                                    <div style="background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.25);border-radius:6px;padding:10px 14px">
                                        <div class="fw-bold small mb-2" style="color:#fbbf24"><i class="bi bi-exclamation-triangle-fill me-1"></i>Config Changed Since This Run</div>
                                        <div id="configDriftList" class="fs-11px" style="color:rgba(255,255,255,.7)"></div>
                                        <div class="fs-10px mt-2" style="color:rgba(255,255,255,.35)">The settings active when this session ran differ from current live config. Go to <a href="{{ route('harness.config') }}" style="color:#fbbf24">Config UI</a> to review.</div>
                                    </div>
                                </div>
                                <!-- Quantum layered graph -->
                                <div class="qgraph-wrapper mb-3" id="workflowGraph"></div>
                                
                                <!-- Detailed Node Inspector -->
                                <div class="card bg-black bg-opacity-25 border border-inverse border-opacity-10 mb-2" id="nodeDetailsCard" style="display: none;">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-3">
                                            <h6 class="card-title mb-0" id="nodeDetailsTitle" style="font-weight: 700; color: #fff;">Node Details</h6>
                                            <span class="badge bg-theme text-black ms-2" id="nodeDetailsMeta"></span>
                                        </div>
                                        <div class="workflow-details-content" id="nodeDetailsContent"></div>
                                    </div>
                                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- SESSION MANAGER -->
        <div class="row mb-3" id="session-manager">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-4">
                            <span class="flex-grow-1"><i class="bi bi-hdd-network text-theme me-2"></i>SESSION MANAGER — ISOLATED PHP SESSIONS</span>
                            <button class="btn btn-sm btn-theme me-2" id="btnRefreshSessions" onclick="loadIsolatedSessions()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                            <button class="btn btn-sm btn-warning me-2" id="btnCleanupSessions" onclick="cleanupOldSessions()">
                                <i class="bi bi-eraser"></i> Cleanup Old
                            </button>
                            <button class="btn btn-sm btn-danger" id="btnPurgeSessions" onclick="purgeAllSessions()">
                                <i class="bi bi-trash"></i> Purge All
                            </button>
                        </div>
                        <p class="small text-inverse text-opacity-50 mb-3">
                            Each Laravel PHP session gets its own phpkaiharness folder with a dedicated SQLite monitor DB
                            and quantum memory DB. This isolates agent traces, caches, and memory per browser session.
                        </p>
                        <div id="sessionsOverview" class="row g-3 mb-3">
                            <div class="col-xl-3 col-md-6">
                                <div class="card bg-black bg-opacity-25 border border-inverse border-opacity-10">
                                    <div class="card-body">
                                        <div class="fs-10px text-inverse text-opacity-50">TOTAL SESSIONS</div>
                                        <div class="fs-4 fw-bold text-theme" id="sessTotalCount">—</div>
                                    </div>
                                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="card bg-black bg-opacity-25 border border-inverse border-opacity-10">
                                    <div class="card-body">
                                        <div class="fs-10px text-inverse text-opacity-50">TOTAL DISK SIZE</div>
                                        <div class="fs-4 fw-bold text-info" id="sessTotalSize">—</div>
                                    </div>
                                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="card bg-black bg-opacity-25 border border-inverse border-opacity-10">
                                    <div class="card-body">
                                        <div class="fs-10px text-inverse text-opacity-50">ISOLATION</div>
                                        <div class="fs-4 fw-bold" id="sessIsolationStatus">—</div>
                                    </div>
                                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="card bg-black bg-opacity-25 border border-inverse border-opacity-10">
                                    <div class="card-body">
                                        <div class="fs-10px text-inverse text-opacity-50">BASE PATH</div>
                                        <div class="fs-10px text-inverse text-opacity-25 font-monospace" id="sessBasePath">—</div>
                                    </div>
                                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0" id="sessionsTable">
                                <thead>
                                    <tr class="text-inverse text-opacity-50 fs-10px">
                                        <th>SESSION ID</th>
                                        <th>SIZE</th>
                                        <th>MONITOR DB</th>
                                        <th>QUANTUM DB</th>
                                        <th>CONTEXT</th>
                                        <th>LAST MODIFIED</th>
                                        <th>ACTIONS</th>
                                    </tr>
                                </thead>
                                <tbody id="sessionsTableBody">
                                    <tr><td colspan="7" class="text-center text-inverse text-opacity-25 py-3">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        <!-- RUN AGENT -->
        <div class="row mb-3" id="run-agent">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-4">
                            <span class="flex-grow-1">RUN AGENT</span>
                            <i class="bi bi-play-circle text-theme"></i>
                        </div>
                        <div class="row g-3">
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">LLM Provider</label>
                                <select class="form-select form-select-sm" id="providerSelect" onchange="onProviderChange()">
                                    <option value="ollama" selected>Ollama</option>
                                    <option value="lmstudio">LM Studio</option>
                                    <option value="openrouter">OpenRouter</option>
                                    <option value="laravel_ai">Laravel AI</option>
                                </select>
                            </div>
                            <div class="col-xl-3 col-md-6" id="connectionGroup" style="display:none">
                                <label class="form-label small text-inverse text-opacity-50">Laravel AI Connection</label>
                                <select class="form-select form-select-sm" id="connectionSelect" onchange="saveSettings();loadModels();">
                                    @foreach(array_keys(config('ai.providers', [])) as $conn)
                                    <option value="{{ $conn }}">{{ $conn }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label small text-inverse text-opacity-50" id="urlLabel">Ollama URL</label>
                                <input type="text" class="form-control form-control-sm" id="ollamaUrl" value="http://localhost:11434" oninput="saveSettings()">
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Agent</label>
                                <select class="form-select form-select-sm" id="agentSelect" onchange="onAgentChange()">
                                    <option value="">Kali WSL Security Assistant (Default)</option>
                                </select>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label small text-inverse text-opacity-50">Model</label>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control form-control-sm flex-grow-1"
                                        id="modelSelect" placeholder="e.g. gemma4:12b-it-qat"
                                        list="modelDatalist" oninput="saveSettings()">
                                    <datalist id="modelDatalist">
                                        <option value="gemma4:12b-it-qat">
                                        <option value="gemma4:e2b">
                                        <option value="llama3.2:3b">
                                    </datalist>
                                    <button class="btn btn-sm btn-outline-theme" onclick="loadModels()" title="Load models from provider">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-inverse text-opacity-50">Prompt</label>
                                <textarea class="form-control form-control-sm" id="promptInput" rows="3"
                                    placeholder="Enter your prompt…"></textarea>
                            </div>
                            <div class="col-12 d-flex flex-wrap align-items-center gap-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optCache" role="switch" {{ (bool)($config['feature_graph']['nodes']['semantic_cache']['enabled'] ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="optCache">Semantic Caching</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optCompact" role="switch" {{ (bool)($config['feature_graph']['nodes']['context_compactor']['enabled'] ?? true) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="optCompact">Context Compaction</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optGuard" role="switch" {{ (bool)($config['feature_graph']['nodes']['guardrails']['enabled'] ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="optGuard">Safety Guardrails</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optQuantum" role="switch" {{ (bool)($config['feature_graph']['nodes']['quantum_harness']['enabled'] ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="optQuantum">Quantum Memory</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex align-items-center gap-3">
                                <button class="btn btn-theme btn-sm px-4" id="runBtn" onclick="runAgent()">
                                    <i class="bi bi-play-fill me-1"></i> Run Agent
                                </button>
                                <span class="fs-11px text-inverse text-opacity-50" id="runMeta"></span>
                            </div>
                            <div class="col-12">
                                <div class="run-output-box" id="runOutput"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                </div>
            </div>
        </div>

        @endif
    </div>
</div>

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="harnessToast" class="toast align-items-center border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
        <div class="d-flex">
            <div class="toast-body" id="harnessToastBody">Message</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="{{ asset('vendor/harness/js/vendor.min.js') }}"></script>
<script src="{{ asset('vendor/harness/js/app.min.js') }}"></script>
<script src="{{ asset('vendor/harness/plugins/datatables.net/js/dataTables.min.js') }}"></script>
<script src="{{ asset('vendor/harness/plugins/datatables.net-bs5/js/dataTables.bootstrap5.min.js') }}"></script>
<script src="{{ asset('vendor/harness/plugins/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('vendor/harness/plugins/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js') }}"></script>
<script src="{{ asset('vendor/harness/plugins/apexcharts/dist/apexcharts.min.js') }}"></script>
<script>
const API_BASE = '{{ route('harness.api') }}';

let activeTraceNodes = [];
let activeTraceSessionId = null;
let activeLayerDefs = [];

const layerDefs = [
    { id: 'input',          label: 'Input Layer',          subtitle: 'Prompt ingestion',          icon: 'bi-send-fill',         color: '#00d2ff', types: ['start'] },
    { id: 'pre_processing', label: 'Pre-Processing Layer', subtitle: 'Feature graph nodes',        icon: 'bi-funnel-fill',       color: '#a855f7', types: ['bootstrap','environment_bootstrap','context_compression','draft_verification','ontology','optimizer','pii_masking','cache','rate_limit','rate_limiting','policy_guardrail','feature_matrix'] },
    { id: 'quantum',        label: 'Quantum Layer',        subtitle: 'Quantum memory & Qwen',     icon: 'bi-atom',              color: '#8b5cf6', types: ['quantum','quantum_collapse'] },
    { id: 'agent_loop',     label: 'Agent Loop Layer',     subtitle: 'LLM + Tool iterations',     icon: 'bi-arrow-repeat',      color: '#3b82f6', types: ['llm_call','tool_call','guardrail','compaction','compression','failover','cache_invalidation'] },
    { id: 'post_processing',label: 'Post-Processing Layer',subtitle: 'Budget, memory & safety',   icon: 'bi-shield-check',      color: '#f97316', types: ['budget','cognitive_memory','feature_matrix'] },
    { id: 'output',         label: 'Output Layer',         subtitle: 'Final response',            icon: 'bi-check-circle-fill', color: '#10b981', types: ['end'] },
];

let mainSessionsTable = null;
let subSessionsTable = null;
let activeMainSessionId = null;

function getFilterData() {
    return {
        method: $('#filterMethod').val(),
        status: $('#filterStatus').val(),
        min_llm: $('#filterMinLlm').val(),
        min_tool: $('#filterMinTool').val()
    };
}

function applyFilters() {
    if (mainSessionsTable) {
        mainSessionsTable.ajax.reload();
    }
    if (subSessionsTable && activeMainSessionId) {
        subSessionsTable.ajax.reload();
    }
    renderActiveFilterBadges();
}

function clearFilters() {
    $('#filterMethod').val('');
    $('#filterStatus').val('');
    $('#filterMinLlm').val('');
    $('#filterMinTool').val('');
    applyFilters();
}

function renderActiveFilterBadges() {
    const container = document.getElementById('activeFiltersContainer');
    if (!container) return;
    container.innerHTML = '';

    const filters = getFilterData();
    const badgeConfigs = [
        { key: 'method', label: 'Method', val: filters.method, resetId: 'filterMethod' },
        { key: 'status', label: 'Status', val: filters.status, resetId: 'filterStatus' },
        { key: 'min_llm', label: 'Min LLMs', val: filters.min_llm, resetId: 'filterMinLlm' },
        { key: 'min_tool', label: 'Min Tools', val: filters.min_tool, resetId: 'filterMinTool' }
    ];

    let hasActive = false;
    badgeConfigs.forEach(bc => {
        if (bc.val) {
            hasActive = true;
            const badge = document.createElement('span');
            badge.className = 'badge bg-black border border-inverse border-opacity-15 text-theme d-inline-flex align-items-center gap-1 fs-11px py-1 px-2';
            badge.innerHTML = `
                <span>${bc.label}: <strong>${escHtml(bc.val)}</strong></span>
                <i class="bi bi-x-circle-fill text-danger ms-1" style="cursor:pointer" onclick="clearSpecificFilter('${bc.resetId}')"></i>
            `;
            container.appendChild(badge);
        }
    });

    if (hasActive) {
        const clearAllBtn = document.createElement('span');
        clearAllBtn.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-20 d-inline-flex align-items-center fs-11px py-1 px-2';
        clearAllBtn.style.cursor = 'pointer';
        clearAllBtn.innerHTML = '<i class="bi bi-trash-fill me-1"></i>Clear All';
        clearAllBtn.onclick = clearFilters;
        container.appendChild(clearAllBtn);
    }
}

function clearSpecificFilter(id) {
    const el = document.getElementById(id);
    if (el) {
        el.value = '';
        applyFilters();
    }
}

function selectMainSession(phpSessionId, rowEl) {
    activeMainSessionId = phpSessionId;
    
    // Highlight active main session row
    document.querySelectorAll('#mainSessionTable tr').forEach(r => r.classList.remove('table-active'));
    if (rowEl) {
        rowEl.classList.add('table-active');
    }

    // Hide placeholder and show table wrapper
    document.getElementById('subSessionsPlaceholder').classList.add('d-none');
    document.getElementById('subSessionsTableWrapper').classList.remove('d-none');

    // Reload sub sessions table
    if (subSessionsTable) {
        subSessionsTable.ajax.reload();
    }
}

$(document).ready(function() {
    renderActiveFilterBadges();

    mainSessionsTable = $('#mainSessionTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: API_BASE + '?action=main_sessions',
            type: 'GET',
            data: function(d) {
                Object.assign(d, getFilterData());
            },
            dataSrc: function(json) {
                $('#mainSessionsCount').text(json.recordsFiltered || 0);
                return json.data || [];
            }
        },
        columns: [
            {
                data: 'php_session_id',
                className: 'font-monospace fs-11px text-theme fw-bold',
                render: function(data, type, row) {
                    const phpSess = data || 'global';
                    const shortPhpSess = phpSess.length > 12 ? phpSess.substring(0, 12) + '…' : phpSess;
                    return `<span title="${phpSess}">${shortPhpSess}</span>`;
                }
            },
            {
                data: 'sub_session_count',
                className: 'text-center fw-bold'
            },
            {
                data: 'total_duration',
                className: 'fs-11px',
                render: function(data) {
                    return (data ? Number(data).toLocaleString() : '0') + 'ms';
                }
            },
            {
                data: 'llm_calls',
                className: 'text-center',
                defaultContent: '0'
            },
            {
                data: 'tool_calls',
                className: 'text-center',
                defaultContent: '0'
            },
            {
                data: 'total_prompt_tokens',
                className: 'text-center fs-11px text-info',
                render: function(data, type, row) {
                    const prompt = Number(row.total_prompt_tokens || 0);
                    const comp = Number(row.total_completion_tokens || 0);
                    return `${prompt.toLocaleString()}↑ / ${comp.toLocaleString()}↓`;
                }
            },
            {
                data: 'last_active',
                className: 'fs-10px text-inverse text-opacity-50',
                render: function(data) {
                    return data || '';
                }
            }
        ],
        order: [[6, 'desc']],
        createdRow: function(row, data) {
            $(row).css('cursor', 'pointer');
            if (activeMainSessionId === data.php_session_id) {
                $(row).addClass('table-active');
            }
            $(row).on('click', function() {
                selectMainSession(data.php_session_id, row);
            });
        },
        language: {
            searchPlaceholder: 'Search main sessions...',
            sSearch: '',
            lengthMenu: '_MENU_ per page',
        },
        dom: '<"d-flex align-items-center mb-3"<"me-auto"l><"ms-auto"f>>t<"d-flex align-items-center mt-3"<"me-auto"i><"ms-auto"p>>',
        pageLength: 5,
        lengthMenu: [5, 10, 20, 50]
    });

    subSessionsTable = $('#subSessionTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: API_BASE + '?action=sessions',
            type: 'GET',
            data: function(d) {
                d.php_session_id = activeMainSessionId || '___none___';
                Object.assign(d, getFilterData());
            },
            dataSrc: function(json) {
                $('#subSessionsCount').text(json.recordsFiltered || 0);
                return json.data || [];
            }
        },
        columns: [
            {
                data: 'id',
                className: 'font-monospace fs-11px fw-bold',
                render: function(data, type, row) {
                    let indicator = '';
                    if (row.parent_session_id && row.parent_session_id !== row.php_session_id) {
                        indicator = '<span class="text-inverse text-opacity-25 me-1">↳</span>';
                    }
                    return indicator + data.substring(0, 8) + '…';
                }
            },
            {
                data: 'method',
                render: function(data, type, row) {
                    const badgeCls = data.includes('fast') ? 'badge-fast-path' : (data.includes('cache') ? 'badge-cache' : 'badge-executor');
                    let interactionBadge = '';
                    if (row.interaction_index !== undefined && row.interaction_index !== null) {
                        interactionBadge = ` <span class="badge rounded-pill fs-10px" style="background:rgba(99,102,241,.2);color:#a5b4fc">#${row.interaction_index}</span>`;
                    }
                    return `<span class="badge fw-bold ${badgeCls}">${data}</span>` + interactionBadge;
                }
            },
            {
                data: 'prompt',
                className: 'text-truncate',
                render: function(data) {
                    const safePrompt = escHtml(data || '');
                    return `<span title="${safePrompt}" style="display:inline-block; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">${safePrompt}</span>`;
                }
            },
            {
                data: 'total_duration_ms',
                className: 'fs-11px',
                render: function(data) {
                    return (data ? Number(data).toLocaleString() : '0') + 'ms';
                }
            },
            {
                data: 'llm_calls',
                className: 'text-center',
                defaultContent: '0'
            },
            {
                data: 'tool_calls',
                className: 'text-center',
                defaultContent: '0'
            },
            {
                data: 'status',
                className: 'text-center',
                render: function(data) {
                    const status = data || 'completed';
                    const color = status === 'completed' ? 'success' : (status === 'running' ? 'warning' : 'danger');
                    return `<span class="badge bg-${color} bg-opacity-20 text-${color}">${status}</span>`;
                }
            },
            {
                data: 'created_at',
                className: 'fs-10px text-inverse text-opacity-50',
                render: function(data) {
                    return data || '';
                }
            }
        ],
        order: [[7, 'desc']],
        createdRow: function(row, data) {
            $(row).css('cursor', 'pointer');
            if (activeTraceSessionId === data.id) {
                $(row).addClass('table-active');
            }
            $(row).on('click', function() {
                loadSessionTrace(data.id, row);
            });
        },
        language: {
            searchPlaceholder: 'Search sub-sessions...',
            sSearch: '',
            lengthMenu: '_MENU_ per page',
        },
        dom: '<"d-flex align-items-center mb-3"<"me-auto"l><"ms-auto"f>>t<"d-flex align-items-center mt-3"<"me-auto"i><"ms-auto"p>>',
        pageLength: 5,
        lengthMenu: [5, 10, 20, 50]
    });
});

async function loadSessionTrace(sessionId, rowEl, attempt = 0) {
    if (sessionId && sessionId.indexOf('<') !== -1) {
        sessionId = sessionId.replace(/<[^>]*>/g, '').trim();
    }
    document.querySelectorAll('#subSessionTable tr').forEach(r => r.classList.remove('table-active'));
    if (rowEl) { rowEl.classList.add('table-active'); }
    activeTraceSessionId = sessionId;
    const traceEmpty = document.getElementById('traceEmpty');
    const traceContent = document.getElementById('traceContent');
    const workflowGraph = document.getElementById('workflowGraph');
    const nodeDetailsCard = document.getElementById('nodeDetailsCard');
    
    traceEmpty.classList.add('collapsed');
    traceContent.classList.add('collapsed');
    nodeDetailsCard.style.display = 'none';
    workflowGraph.innerHTML = '<div class="text-center py-4 text-inverse text-opacity-50"><span class="spinner-border spinner-border-sm me-2"></span>Loading trace…</div>';

    try {
        const res  = await fetch(`${API_BASE}?action=trace&id=${encodeURIComponent(sessionId)}`);
        const json = await res.json();
        if ((!json.success || !json.session) && json.state === 'not_found' && attempt < 4) {
            workflowGraph.innerHTML = '<div class="text-center py-4 text-inverse text-opacity-50"><span class="spinner-border spinner-border-sm me-2"></span>Trace is still being indexed…</div>';
            setTimeout(() => loadSessionTrace(sessionId, rowEl, attempt + 1), 700 + (attempt * 500));
            return;
        }
        if (!json.success || !json.session) {
            showToast(json.error || 'Trace not found.', 'error');
            traceEmpty.classList.remove('collapsed');
            workflowGraph.innerHTML = '';
            return;
        }
        if (json.state === 'pending' && attempt < 6) {
            workflowGraph.innerHTML = '<div class="text-center py-4 text-inverse text-opacity-50"><span class="spinner-border spinner-border-sm me-2"></span>Trace is pending…</div>';
            setTimeout(() => loadSessionTrace(sessionId, rowEl, attempt + 1), 1000 + (attempt * 500));
            return;
        }
        const data = json.session;
        document.getElementById('traceSessionId').textContent   = `Session ${data.id.substring(0,13)}…`;
        document.getElementById('traceSessionTime').textContent = `Captured: ${data.created_at}`;
        document.getElementById('traceMethodBadge').textContent = data.method;
        document.getElementById('traceMethodBadge').style.display = '';

        const settings = safeParseJSON(data.settings) || {};

        function isEnabled(key, defaultValue = true) {
            // Check feature_graph.nodes first
            if (key === 'compaction') {
                if (settings.feature_graph?.nodes?.context_compactor?.enabled !== undefined) {
                    return !!settings.feature_graph.nodes.context_compactor.enabled;
                }
                return settings.compaction?.strategy && settings.compaction.strategy !== 'none';
            }
            // Map legacy keys to feature_graph node names
            const graphKeyMap = {
                'draft_verification.enabled': 'draft_verification',
                'ontology.enabled': 'ontology_injection',
                'quantum_harness.enabled': 'quantum_harness',
                'optimizer.enabled': 'model_optimizer',
                'cache.enabled': 'semantic_cache',
                'guardrails.enabled': 'guardrails',
                'cognitive_memory.enabled': 'cognitive_memory',
                'pii_masking.enabled': null,
                'rate_limiting.enabled': null,
                'policy_guardrail.enabled': null,
                'failover.enabled': null,
                'budget.enabled': null,
                'compression.enabled': 'context_compression',
                'compaction.compression.enabled': 'context_compression',
                'bootstrap.enabled': 'environment_bootstrap',
                'feature_matrix.enabled': null,
            };
            const graphNode = graphKeyMap[key];
            if (graphNode && settings.feature_graph?.nodes?.[graphNode]?.enabled !== undefined) {
                return !!settings.feature_graph.nodes[graphNode].enabled;
            }
            // Fall back to legacy config path
            const parts = key.split('.');
            let obj = settings;
            for (let part of parts) {
                if (!obj || typeof obj !== 'object') { return defaultValue; }
                obj = obj[part];
            }
            return obj !== undefined ? !!obj : defaultValue;
        }

        activeTraceNodes = [];

        // 1. Start node
        activeTraceNodes.push({
            type: 'start',
            title: 'Initial Prompt',
            meta: 'Loop Trigger',
            duration: 'Start',
            tokens: '',
            color: '#00d2ff',
            icon: 'bi-send-fill',
            payload: { prompt: data.prompt },
            response: null
        });

        // Helper to check if step of type exists in details
        const details = data.details || [];
        function getStep(type) {
            return details.find(d => d.type === type);
        }

        // Define our standard middleware pipeline checks (pre-processing layer)
        const pipelineFeatures = [
            { key: 'bootstrap.enabled',          default: false, type: 'bootstrap',          title: 'Bootstrap',          meta: 'Environment snapshot',      icon: 'bi-info-circle',         color: '#00d2ff' },
            { key: 'draft_verification.enabled', default: false, type: 'draft_verification', title: 'Draft Verification', meta: 'Fast-draft pass',            icon: 'bi-clipboard-check',     color: '#10b981' },
            { key: 'ontology.enabled',           default: false, type: 'ontology',           title: 'Ontology RAG',       meta: 'Context Injection',          icon: 'bi-diagram-3-fill',      color: '#ec4899' },
            { key: 'quantum_harness.enabled',    default: false, type: 'quantum',            title: 'Quantum Memory',     meta: 'Memory envelope injection',  icon: 'bi-atom',                color: '#8b5cf6' },
            { key: 'optimizer.enabled',          default: false, type: 'optimizer',          title: 'Optimizer',          meta: 'Prompt optimization',        icon: 'bi-magic',               color: '#a855f7' },
            { key: 'pii_masking.enabled',        default: false, type: 'pii_masking',        title: 'PII Masking',        meta: 'Redacted keys',              icon: 'bi-shield-lock-fill',    color: '#f43f5e' },
            { key: 'cache.enabled',              default: false, type: 'cache',              title: 'Semantic Cache',     meta: 'Prompt caching',             icon: 'bi-lightning-charge-fill',color: '#10b981' },
            { key: 'rate_limiting.enabled',      default: false, type: 'rate_limit',         title: 'Rate Limiting',      meta: 'Call throttle',              icon: 'bi-speedometer2',        color: '#f59e0b' },
            { key: 'policy_guardrail.enabled',   default: false, type: 'policy_guardrail',   title: 'Policy Check',       meta: 'Gate middleware',            icon: 'bi-person-lock',         color: '#f97316' },
        ];

        // Process pre-loop & check middleware stages
        pipelineFeatures.forEach(feat => {
            const enabled = isEnabled(feat.key, feat.default);
            const step = getStep(feat.type);

            if (!enabled) {
                activeTraceNodes.push({
                    type: feat.type,
                    title: feat.title + ' [Disabled]',
                    meta: feat.meta,
                    duration: 'Deactivated',
                    tokens: '',
                    color: '#ef4444',
                    icon: feat.icon,
                    payload: { status: 'Feature is deactivated in harness configuration.' },
                    response: null,
                    source: 'config',
                    isDisabled: true
                });
            } else if (!step) {
                // Enabled but did not execute in this run
                activeTraceNodes.push({
                    type: feat.type,
                    title: feat.title + ' [Activated]',
                    meta: feat.meta,
                    duration: 'Idle / Skipped',
                    tokens: '',
                    color: '#10b981',
                    icon: feat.icon,
                    payload: { status: 'Feature is active but did not record telemetry in this run.' },
                    response: null,
                    source: 'missing_telemetry',
                    isIdle: true
                });
            } else {
                // Enabled and did execute!
                let payload = safeParseJSON(step.payload);
                let resp    = safeParseJSON(step.response);
                activeTraceNodes.push({
                    type: step.type,
                    title: feat.title + ' [Active]',
                    meta: feat.meta,
                    duration: `${step.duration_ms}ms`,
                    tokens: step.tokens_prompt ? `${step.tokens_prompt}↑ / ${step.tokens_completion}↓` : '',
                    color: feat.color,
                    icon: feat.icon,
                    payload: payload,
                    response: resp,
                    source: 'recorded'
                });
            }
        });

        // 3. Now render loop executions (LLM Calls & Tool Calls)
        details.forEach(step => {
            // Skip the middleware steps we already placed above to avoid duplicates
            if (['bootstrap', 'draft_verification', 'ontology', 'quantum', 'optimizer', 'pii_masking', 'cache', 'rate_limit', 'rate_limiting', 'policy_guardrail', 'environment_bootstrap', 'context_compression', 'feature_matrix', 'cache_invalidation'].includes(step.type)) {
                return;
            }

            let payload = safeParseJSON(step.payload);
            let resp    = safeParseJSON(step.response);
            let node = {
                type: step.type,
                title: '',
                meta: '',
                duration: `${step.duration_ms}ms`,
                tokens: step.tokens_prompt ? `${step.tokens_prompt}↑ / ${step.tokens_completion}↓` : '',
                color: '#888',
                icon: 'bi-gear-fill',
                payload: payload,
                response: resp,
                source: 'recorded'
            };

            if (step.type === 'llm_call') {
                node.title = 'LLM Generation';
                node.meta = step.model ? step.model : 'LLM Call';
                node.color = '#3b82f6';
                node.icon = 'bi-cpu-fill';
            } else if (step.type === 'tool_call') {
                node.title = `Tool: ${step.name||'?'}`;
                node.meta = 'Tool execution';
                node.color = '#10b981';
                node.icon = 'bi-tools';
            } else if (step.type === 'guardrail') {
                node.title = 'Guardrails';
                node.meta = 'Safety checks';
                node.color = '#10b981';
                node.icon = 'bi-shield-check';
            } else if (step.type === 'compaction') {
                node.title = 'Compaction';
                node.meta = 'Context window shrink';
                node.color = '#06b6d4';
                node.icon = 'bi-archive-fill';
            } else if (step.type === 'compression' || step.type === 'context_compression') {
                node.title = 'Context Compression';
                node.meta = 'AST/signature compression';
                node.color = '#10b981';
                node.icon = 'bi-file-earmark-zip';
            } else if (step.type === 'cache_invalidation') {
                node.title = 'Cache Invalidation';
                node.meta = 'Stale cache cleared';
                node.color = '#f59e0b';
                node.icon = 'bi-lightning-charge-fill';
            } else if (step.type === 'feature_matrix') {
                node.title = 'Feature Matrix';
                node.meta = 'Resolved feature snapshot';
                node.color = '#00d2ff';
                node.icon = 'bi-diagram-2-fill';
            } else if (step.type === 'failover') {
                node.title = 'LLM Failover';
                node.meta = 'Retry Client';
                node.color = '#ef4444';
                node.icon = 'bi-arrow-repeat';
            }
            activeTraceNodes.push(node);
        });

        // 4. Post-loop features: Gating, budget, memory, etc.
        // These cover all remaining active features not already handled in pre-processing or agent-loop.
        const postFeatures = [
            { key: 'failover.enabled',           default: false,             type: 'failover',         title: 'LLM Failover',         meta: 'Retry client checks',        icon: 'bi-arrow-repeat',      color: '#ef4444' },
            { key: 'budget.enabled',             default: false,             type: 'budget',           title: 'Thinking Budget',      meta: 'Token budget gate',          icon: 'bi-stopwatch',         color: '#ef4444' },
            { key: 'guardrails.enabled',         default: false,             type: 'guardrail',        title: 'Safety Guardrails',    meta: 'Safety checker',             icon: 'bi-shield-check',      color: '#10b981' },
            { key: 'compaction.strategy',        default: 'sliding_window',  type: 'compaction',       title: 'Context Compaction',   meta: 'Context shrinker',           icon: 'bi-archive-fill',      color: '#06b6d4' },
            { key: 'compaction.compression.enabled', default: false,         type: 'compression',      title: 'Context Compression',  meta: 'Comment stripper',           icon: 'bi-file-earmark-zip',  color: '#10b981' },
            { key: 'cognitive_memory.enabled',   default: false,             type: 'cognitive_memory', title: 'Cognitive Memory',     meta: 'Fact extraction & dedup',    icon: 'bi-brain',             color: '#8b5cf6' },
            { key: 'quantum_harness.enabled',    default: false,             type: 'quantum_collapse', title: 'Quantum Collapse',     meta: 'Post-flight decomposition',  icon: 'bi-diagram-2-fill',   color: '#a855f7' },
            { key: 'feature_matrix.enabled',     default: true,              type: 'feature_matrix',   title: 'Feature Matrix',       meta: 'Resolved config snapshot',   icon: 'bi-diagram-2-fill',     color: '#00d2ff' },
        ];

        postFeatures.forEach(feat => {
            // If already executed (and we placed it in loop details), skip adding it again
            if (details.find(d => d.type === feat.type)) {
                return;
            }

            const enabled = isEnabled(feat.key, feat.default);
            if (!enabled) {
                activeTraceNodes.push({
                    type: feat.type,
                    title: feat.title + ' [Disabled]',
                    meta: feat.meta,
                    duration: 'Deactivated',
                    tokens: '',
                    color: '#ef4444',
                    icon: feat.icon,
                    payload: { status: 'Feature is deactivated in configuration.' },
                    response: null,
                    source: 'config',
                    isDisabled: true
                });
            } else {
                activeTraceNodes.push({
                    type: feat.type,
                    title: feat.title + ' [Activated]',
                    meta: feat.meta,
                    duration: 'Idle / Skipped',
                    tokens: '',
                    color: '#10b981',
                    icon: feat.icon,
                    payload: { status: 'Feature is active but did not record telemetry in this session.' },
                    response: null,
                    source: 'missing_telemetry',
                    isIdle: true
                });
            }
        });

        // 5. Final Response
        activeTraceNodes.push({
            type: 'end',
            title: 'Final Response',
            meta: 'Loop Finished',
            duration: `${data.total_duration_ms}ms`,
            tokens: '',
            color: '#10b981',
            icon: 'bi-check-circle-fill',
            payload: null,
            response: { response: data.response || 'No response returned' },
            source: 'recorded'
        });

        // ── Quantum Layered Graph Rendering ──
        // Group nodes into quantum layers (layerDefs is in outer scope)
        const layerMap = {};
        activeTraceNodes.forEach((node, idx) => {
            node._idx = idx;
            const layer = layerDefs.find(l => l.types.includes(node.type));
            const layerId = layer ? layer.id : 'agent_loop';
            if (!layerMap[layerId]) { layerMap[layerId] = []; }
            layerMap[layerId].push(node);
        });

        // Count stats
        let activeCount = 0, disabledCount = 0, idleCount = 0;
        activeTraceNodes.forEach(n => {
            if (n.isDisabled) { disabledCount++; }
            else if (n.isIdle) { idleCount++; }
            else { activeCount++; }
        });

        // Build summary bar
        let graphHtml = `
            <div class="qgraph-summary">
                <div class="qgraph-summary-item"><span class="qgraph-summary-dot" style="background:#10b981"></span> Active: ${activeCount}</div>
                <div class="qgraph-summary-item"><span class="qgraph-summary-dot" style="background:#f59e0b"></span> Idle: ${idleCount}</div>
                <div class="qgraph-summary-item"><span class="qgraph-summary-dot" style="background:#ef4444"></span> Disabled: ${disabledCount}</div>
                <div class="qgraph-summary-item"><span class="qgraph-summary-dot" style="background:#3b82f6"></span> Total Nodes: ${activeTraceNodes.length}</div>
                <div class="qgraph-summary-item" style="margin-left:auto;color:rgba(255,255,255,0.3)">${escHtml(data.total_duration_ms)}ms total</div>
            </div>
        `;

        // Render each layer
        layerDefs.forEach((layer, layerIdx) => {
            const nodes = layerMap[layer.id] || [];
            if (nodes.length === 0) { return; }

            const activeInLayer = nodes.filter(n => !n.isDisabled && !n.isIdle).length;

            graphHtml += `
                <div class="qgraph-layer" style="--layer-color: ${layer.color}">
                    <div class="qgraph-layer-header">
                        <i class="bi ${layer.icon} qgraph-layer-icon"></i>
                        <span class="qgraph-layer-title">${escHtml(layer.label)}</span>
                        <span class="qgraph-layer-subtitle">${escHtml(layer.subtitle)}</span>
                        <span class="qgraph-layer-count">${activeInLayer} active / ${nodes.length} total</span>
                    </div>
                    <div class="qgraph-nodes-row">
            `;

            nodes.forEach((node, nodeIdx) => {
                let extraClass = '';
                let statusClass = 'on';
                if (node.isDisabled) { extraClass = 'disabled-node'; statusClass = 'off'; }
                else if (node.isIdle) { extraClass = 'idle-node'; statusClass = 'idle'; }

                // Node card with card-arrow corners matching main UI
                graphHtml += `
                    <div class="qgraph-node ${extraClass}" onclick="selectWorkflowNode(${node._idx})" id="node-${node._idx}" style="--node-color: ${node.color}">
                        <div class="qgraph-node-status ${statusClass}"></div>
                        <div class="qgraph-node-icon" style="color: ${node.color}"><i class="bi ${node.icon}"></i></div>
                        <div class="qgraph-node-title" title="${escHtml(node.title)}">${escHtml(node.title)}</div>
                        <div class="qgraph-node-meta" title="${escHtml(node.meta)}">${escHtml(node.meta)}</div>
                        <div class="qgraph-node-metrics">
                            <span>${escHtml(node.duration)}</span>
                            <span style="color: var(--node-color); font-weight: bold;">${escHtml(node.tokens)}</span>
                        </div>
                        <div class="card-arrow"><div class="card-arrow-top-left"></div><div class="card-arrow-top-right"></div><div class="card-arrow-bottom-left"></div><div class="card-arrow-bottom-right"></div></div>
                    </div>
                `;

                // Animated horizontal flow line between nodes in same layer
                if (nodeIdx < nodes.length - 1) {
                    const flowColor = node.isDisabled ? 'rgba(239,68,68,0.3)' : node.color;
                    const flowOpacity = node.isDisabled ? 0.3 : 0.6;
                    graphHtml += `
                        <div class="qgraph-flow-h">
                            <svg width="32" height="60" viewBox="0 0 32 60">
                                <line x1="0" y1="30" x2="32" y2="30" stroke="${flowColor}" stroke-width="1.5" opacity="${flowOpacity}" class="qgraph-flow-line" />
                                <line x1="0" y1="30" x2="32" y2="30" stroke="${flowColor}" stroke-width="3" opacity="0.15" class="qgraph-flow-glow" />
                                <circle cx="0" cy="30" r="2.5" fill="${flowColor}" class="qgraph-flow-particle" />
                            </svg>
                        </div>
                    `;
                }
            });

            graphHtml += `</div></div>`;

            // Animated vertical flow line between layers
            if (layerIdx < layerDefs.length - 1) {
                const nextLayer = layerDefs[layerIdx + 1];
                const nextNodes = layerMap[nextLayer.id] || [];
                if (nextNodes.length > 0) {
                    const connColor = activeInLayer > 0 ? layer.color : 'rgba(255,255,255,0.12)';
                    const connOpacity = activeInLayer > 0 ? 0.5 : 0.2;
                    graphHtml += `
                        <div class="qgraph-connector">
                            <svg width="40" height="32" class="qgraph-connector-svg" viewBox="0 0 40 32">
                                <line x1="20" y1="0" x2="20" y2="32" stroke="${connColor}" stroke-width="1.5" opacity="${connOpacity}" class="qgraph-flow-v-line" />
                                <line x1="20" y1="0" x2="20" y2="32" stroke="${connColor}" stroke-width="3" opacity="0.1" class="qgraph-flow-glow" />
                                <circle cx="20" cy="0" r="2.5" fill="${connColor}" class="qgraph-flow-v-particle" />
                            </svg>
                        </div>
                    `;
                }
            }
        });

        workflowGraph.innerHTML = graphHtml;
        traceContent.classList.remove('collapsed');
        document.getElementById('debugReportBtn').style.display = '';

        // Auto-select the first node (Start) or the first LLM call if one exists
        let selectIdx = 0;
        const firstLlm = activeTraceNodes.findIndex(n => n.type === 'llm_call');
        if (firstLlm !== -1) { selectIdx = firstLlm; }
        selectWorkflowNode(selectIdx);

    } catch(e) {
        console.error(e); showToast('Failed to load trace.','error');
        workflowGraph.innerHTML = '';
        traceEmpty.classList.remove('collapsed');
    }
}

function selectWorkflowNode(idx) {
    document.querySelectorAll('.qgraph-node').forEach(node => node.classList.remove('active'));
    const activeNodeEl = document.getElementById(`node-${idx}`);
    if (activeNodeEl) { activeNodeEl.classList.add('active'); }

    const node = activeTraceNodes[idx];
    if (!node) { return; }

    const nodeDetailsCard = document.getElementById('nodeDetailsCard');
    const nodeDetailsTitle = document.getElementById('nodeDetailsTitle');
    const nodeDetailsMeta = document.getElementById('nodeDetailsMeta');
    const nodeDetailsContent = document.getElementById('nodeDetailsContent');

    nodeDetailsTitle.textContent = node.title;
    const layerDef = layerDefs ? layerDefs.find(l => l.types.includes(node.type)) : null;
    const layerName = layerDef ? layerDef.label : 'Unknown Layer';
    let statusBadge = '';
    if (node.isDisabled) { statusBadge = '<span class="badge bg-danger bg-opacity-25 text-danger ms-2" style="font-size:9px">DISABLED</span>'; }
    else if (node.source === 'missing_telemetry') { statusBadge = '<span class="badge bg-warning bg-opacity-25 text-warning ms-2" style="font-size:9px">MISSING TELEMETRY</span>'; }
    else if (node.isIdle) { statusBadge = '<span class="badge bg-warning bg-opacity-25 text-warning ms-2" style="font-size:9px">IDLE</span>'; }
    else { statusBadge = '<span class="badge bg-success bg-opacity-25 text-success ms-2" style="font-size:9px">ACTIVE</span>'; }
    nodeDetailsTitle.innerHTML = `${escHtml(node.title)} <span class="badge bg-theme bg-opacity-25 text-theme ms-2" style="font-size:9px">${escHtml(layerName)}</span>${statusBadge}`;
    nodeDetailsMeta.textContent = `${node.duration} ${node.tokens ? '· ' + node.tokens : ''}`;
    nodeDetailsCard.style.display = 'block';

    const sourceLabel = node.source === 'recorded' ? 'Recorded in monitor SQLite' : (node.source === 'missing_telemetry' ? 'Enabled in config but no monitor row was recorded' : 'Configuration snapshot');
    let html = `<div class="mb-3"><div class="trace-label">DATA SOURCE</div><div class="text-inverse text-opacity-75 fs-12px">${escHtml(sourceLabel)}</div></div>`;
    if (node.type === 'start') {
        html += `
            <div class="trace-label">USER PROMPT</div>
            <div class="bg-black bg-opacity-35 p-3 rounded font-monospace fs-12px whitespace-pre-wrap text-inverse text-opacity-90">${escHtml(node.payload.prompt)}</div>
        `;
    } else if (node.type === 'end') {
        html += `
            <div class="trace-label">AGENT FINAL RESPONSE</div>
            <div class="bg-black bg-opacity-35 p-3 rounded font-monospace fs-12px whitespace-pre-wrap text-inverse text-opacity-90">${escHtml(node.response.response)}</div>
        `;
    } else {
        const payloadStr = node.payload ? JSON.stringify(node.payload, null, 2) : 'No payload recorded';
        const responseStr = node.response ? JSON.stringify(node.response, null, 2) : 'No response recorded';
        html += `
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="trace-label">INPUT / REQUEST PAYLOAD</div>
                    <pre class="bg-black bg-opacity-35 p-3 rounded text-info fs-11px overflow-auto" style="max-height: 350px;">${escHtml(payloadStr)}</pre>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="trace-label">OUTPUT / RESPONSE PAYLOAD</div>
                    <pre class="bg-black bg-opacity-35 p-3 rounded text-success fs-11px overflow-auto" style="max-height: 350px;">${escHtml(responseStr)}</pre>
                </div>
            </div>
        `;
    }
    nodeDetailsContent.innerHTML = html;
}

async function downloadDebugReport() {
    if (!activeTraceSessionId) { showToast('No session selected.', 'error'); return; }

    const btn = document.getElementById('debugReportBtn');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Evaluating...';
    btn.disabled = true;

    try {
        const res = await fetch(`${API_BASE}?action=debug_report&id=${encodeURIComponent(activeTraceSessionId)}`);
        const json = await res.json();

        if (!json.success) {
            showToast(json.error || 'Failed to generate debug report.', 'error');
            return;
        }

        // Show config drift panel if detected
        const driftBadge = document.getElementById('configDriftBadge');
        const syncBadge  = document.getElementById('configSyncBadge');
        const driftPanel = document.getElementById('configDriftPanel');
        const driftList  = document.getElementById('configDriftList');
        if (json.has_config_drift && json.config_drift && json.config_drift.length > 0) {
            driftBadge.style.display = '';
            syncBadge.style.display  = 'none';
            let html = '<table style="width:100%;border-collapse:collapse">';
            html += '<tr><th style="text-align:left;padding:2px 8px;color:rgba(255,255,255,.45);font-weight:600">Flag</th>';
            html += '<th style="text-align:center;padding:2px 8px;color:rgba(255,255,255,.45);font-weight:600">At Run</th>';
            html += '<th style="text-align:center;padding:2px 8px;color:rgba(255,255,255,.45);font-weight:600">Live Now</th></tr>';
            json.config_drift.forEach(d => {
                const atRun = d.at_run !== null && d.at_run !== undefined ? String(d.at_run) : '—';
                const live  = d.live  !== null && d.live  !== undefined ? String(d.live)  : '—';
                const changed = atRun !== live;
                html += `<tr>`;
                html += `<td style="padding:2px 8px;font-family:monospace">${escHtml(d.key)}</td>`;
                html += `<td style="text-align:center;padding:2px 8px;color:${atRun==='true'||atRun==='1'?'#4ade80':'#f87171'}">${escHtml(atRun)}</td>`;
                html += `<td style="text-align:center;padding:2px 8px;color:${live==='true'||live==='1'?'#4ade80':'#f87171'};font-weight:${changed?700:400}">${escHtml(live)}</td>`;
                html += `</tr>`;
            });
            html += '</table>';
            driftList.innerHTML = html;
            driftPanel.style.display = '';
        } else {
            driftBadge.style.display = 'none';
            syncBadge.style.display  = '';
            driftPanel.style.display = 'none';
        }

        // Show evaluation summary as a toast
        const s = json.summary;
        const hasFails = s.fail > 0;
        const hasWarns = s.warn > 0;
        let toastMsg = `Trace evaluated: ${s.pass}✓ ${s.fail}✗ ${s.warn}⚠ ${s.skip}○ ${s.info}ℹ`;
        if (json.has_config_drift) toastMsg += ' · ⚠ Config drift detected';
        showToast(toastMsg, hasFails ? 'error' : (hasWarns ? 'warning' : 'success'));

        // Download the report as a .log file
        const blob = new Blob([json.report], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `harness-trace-${activeTraceSessionId.substring(0, 8)}.log`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (e) {
        console.error(e);
        showToast('Failed to fetch debug report.', 'error');
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

function toggleConfigDriftPanel() {
    const p = document.getElementById('configDriftPanel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
}

function escHtml(s) { if (!s) { return ''; } if (typeof s!=='string') { s=String(s); } return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function safeParseJSON(val) { if (val === null || val === undefined || val === '') { return null; } try { return JSON.parse(val); } catch(e) { return val; } }

const defaultUrls = { ollama:'http://localhost:11434', lmstudio:'http://localhost:1234', openrouter:'https://openrouter.ai', laravel_ai:'' };
const defaultModels = { ollama:'gemma4:12b-it-qat', lmstudio:'lmstudio-community/gemma-2b-it-GGUF', openrouter:'meta-llama/llama-3-8b-instruct', laravel_ai:'gemma4:12b-it-qat' };
let discoveredAgents = [];

function loadSettings() {
    const provider = localStorage.getItem('phk_provider') || 'ollama';
    document.getElementById('providerSelect').value = provider;
    document.getElementById('ollamaUrl').value = localStorage.getItem('phk_url') || defaultUrls[provider];
    document.getElementById('modelSelect').value = localStorage.getItem('phk_model') || defaultModels[provider];
    onProviderChange(true);
    loadAgents();
}
function saveSettings() {
    localStorage.setItem('phk_provider', document.getElementById('providerSelect').value);
    localStorage.setItem('phk_agent',    document.getElementById('agentSelect').value);
    localStorage.setItem('phk_url',      document.getElementById('ollamaUrl').value);
    localStorage.setItem('phk_model',    document.getElementById('modelSelect').value);
    const connEl = document.getElementById('connectionSelect');
    if (connEl) { localStorage.setItem('phk_connection', connEl.value); }
}
function onProviderChange(isInit=false) {
    const provider  = document.getElementById('providerSelect').value;
    const urlEl     = document.getElementById('ollamaUrl');
    const modelEl   = document.getElementById('modelSelect');
    const connGroup = document.getElementById('connectionGroup');
    const urlGroup  = urlEl.closest('.col-xl-3')||urlEl.closest('.col-md-6');
    if (!isInit) { urlEl.value=defaultUrls[provider]||''; modelEl.value=defaultModels[provider]||''; }
    if (provider==='laravel_ai') { if(connGroup){connGroup.style.display='';} if(urlGroup){urlGroup.style.display='none';} }
    else { if(connGroup){connGroup.style.display='none';} if(urlGroup){urlGroup.style.display='';} }
    const labels = { ollama:'Ollama URL', lmstudio:'LM Studio URL', openrouter:'OpenRouter URL' };
    document.getElementById('urlLabel').textContent = labels[provider]||'URL';
    saveSettings();
    loadModels();
}
async function loadAgents() {
    try {
        const r = await fetch(`${API_BASE}?action=agents`);
        const d = await r.json();
        if (d.success && d.agents) {
            discoveredAgents = d.agents;
            const sel = document.getElementById('agentSelect');
            sel.innerHTML = '<option value="">Kali WSL Security Assistant (Default)</option>';
            d.agents.forEach(a => { const o = document.createElement('option'); o.value=a.class; o.textContent=`${a.name} (${a.class})`; sel.appendChild(o); });
            const saved = localStorage.getItem('phk_agent')||'';
            if (saved) { sel.value = saved; onAgentChange(); }
        }
    } catch(e) {}
}
function onAgentChange() {
    const cls   = document.getElementById('agentSelect').value;
    const agent = discoveredAgents.find(a => a.class===cls);
    if (agent) {
        if (agent.provider) { document.getElementById('providerSelect').value=agent.provider; onProviderChange(true); }
        if (agent.model)    { document.getElementById('modelSelect').value=agent.model; }
    }
    saveSettings();
}
async function loadModels() {
    const provider = document.getElementById('providerSelect').value;
    const url      = document.getElementById('ollamaUrl').value.trim();
    const connEl   = document.getElementById('connectionSelect');
    const conn     = connEl ? connEl.value : '';
    try {
        const r = await fetch(`${API_BASE}?action=models&provider=${provider}&connection=${conn}&url=${encodeURIComponent(url)}`);
        const d = await r.json();
        if (d.success && d.models && d.models.length) {
            const dl = document.getElementById('modelDatalist');
            dl.innerHTML = '';
            d.models.forEach(m => { const o=document.createElement('option'); o.value=m; dl.appendChild(o); });
        }
    } catch(e) {}
}
async function runAgent() {
    const provider = document.getElementById('providerSelect').value;
    const agent    = document.getElementById('agentSelect').value;
    const url      = document.getElementById('ollamaUrl').value.trim();
    const model    = document.getElementById('modelSelect').value.trim();
    const prompt   = document.getElementById('promptInput').value.trim();
    const connEl   = document.getElementById('connectionSelect');
    const conn     = connEl ? connEl.value : '';
    const cache    = document.getElementById('optCache').checked;
    const compact  = document.getElementById('optCompact').checked;
    const guard    = document.getElementById('optGuard').checked;
    const quantum  = document.getElementById('optQuantum').checked;
    if (!prompt) { showToast('Please enter a prompt','error'); return; }
    if (!model)  { showToast('Please enter a model name','error'); return; }
    const btn  = document.getElementById('runBtn');
    const out  = document.getElementById('runOutput');
    const meta = document.getElementById('runMeta');
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Running…';
    out.className='run-output-box visible'; out.textContent=`Executing agent loop with ${model}…`; meta.textContent='';
    saveSettings();
    try {
        const start = Date.now();
        const r = await fetch(`${API_BASE}?action=run`, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''}, body:JSON.stringify({provider,connection:conn,agent,url,model,prompt,cache,compact,guard,quantum}) });
        const d = await r.json();
        const elapsed = ((Date.now()-start)/1000).toFixed(2);
        if (d.success) { out.textContent=d.response; meta.textContent=`Session: ${d.sessionId} · ${elapsed}s`; showToast('Agent completed','success'); setTimeout(()=>location.reload(),2500); }
        else           { out.textContent='⚠️ Error: '+d.error; showToast('Agent error','error'); }
    } catch(e) { out.textContent='⚠️ Request failed: '+e.message; showToast('Request failed','error'); }
    finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-play-fill me-1"></i> Run Agent'; }
}
function showToast(msg,type='info') {
    const el=document.getElementById('harnessToast'); const body=document.getElementById('harnessToastBody');
    body.textContent=msg; el.classList.remove('text-bg-success','text-bg-danger','text-bg-secondary');
    if (type==='success'){el.classList.add('text-bg-success');} else if(type==='error'){el.classList.add('text-bg-danger');} else{el.classList.add('text-bg-secondary');}
    bootstrap.Toast.getOrCreateInstance(el).show();
}
loadSettings();

// ── Session Manager ──
const SESSIONS_API = '{{ route("harness.api.sessions.list") }}';

function loadIsolatedSessions() {
    fetch(SESSIONS_API)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showToast('Failed to load sessions', 'error'); return; }
            document.getElementById('sessTotalCount').textContent = data.total || 0;
            document.getElementById('sessTotalSize').textContent = formatBytes(data.total_size || 0);
            document.getElementById('sessIsolationStatus').innerHTML = data.isolation_enabled
                ? '<span class="text-success">ENABLED</span>'
                : '<span class="text-danger">DISABLED</span>';
            document.getElementById('sessBasePath').textContent = data.base_path || '—';

            if ($.fn.DataTable.isDataTable('#sessionsTable')) {
                $('#sessionsTable').DataTable().destroy();
            }

            const tbody = document.getElementById('sessionsTableBody');
            if (!data.sessions || data.sessions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-inverse text-opacity-25 py-3">No isolated sessions found.</td></tr>';
                return;
            }
            tbody.innerHTML = data.sessions.map(s => {
                const shortId = s.id.length > 20 ? s.id.substring(0,8) + '…' + s.id.substring(s.id.length-8) : s.id;
                return '<tr>' +
                    '<td class="font-monospace small text-theme" style="cursor:pointer; font-weight:bold" title="Click to filter recent sessions by this ID" onclick="filterRecentSessions(\''+s.id+'\')">'+shortId+' <i class="bi bi-funnel fs-9px ms-1"></i></td>' +
                    '<td>'+s.size_human+'</td>' +
                    '<td>'+(s.monitor_db ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>')+'</td>' +
                    '<td>'+(s.quantum_db ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>')+'</td>' +
                    '<td>'+(s.context ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>')+'</td>' +
                    '<td class="small text-inverse text-opacity-50">'+s.last_modified+'</td>' +
                    '<td><button class="btn btn-xs btn-danger" onclick="deleteSession(\''+s.id+'\')"><i class="bi bi-trash"></i></button></td>' +
                '</tr>';
            }).join('');

            $('#sessionsTable').DataTable({
                order: [[5, 'desc']],
                pageLength: 5,
                lengthMenu: [5, 10, 20, 50],
                language: {
                    searchPlaceholder: 'Filter sessions...',
                    sSearch: '',
                    lengthMenu: '_MENU_ per page',
                },
                dom: '<"d-flex align-items-center mb-2"<"me-auto"l><"ms-auto"f>>t<"d-flex align-items-center mt-2"<"me-auto"i><"ms-auto"p>>'
            });
        })
        .catch(err => { showToast('Failed to load sessions: '+err.message, 'error'); });
}

let activePhpSessionFilter = null;
function filterRecentSessions(phpSessionId) {
    if (activeMainSessionId === phpSessionId) {
        activeMainSessionId = null;
        document.querySelectorAll('#mainSessionTable tr').forEach(r => r.classList.remove('table-active'));
        document.getElementById('subSessionsPlaceholder').classList.remove('d-none');
        document.getElementById('subSessionsTableWrapper').classList.add('d-none');
        if (subSessionsTable) {
            subSessionsTable.ajax.reload();
        }
        showToast('Cleared Main Session filter.', 'info');
    } else {
        // Find row in main session table to highlight it if it exists
        let targetRow = null;
        $('#mainSessionTable tbody tr').each(function() {
            const data = mainSessionsTable ? mainSessionsTable.row(this).data() : null;
            if (data && data.php_session_id === phpSessionId) {
                targetRow = this;
            }
        });
        selectMainSession(phpSessionId, targetRow);
        showToast(`Selected Main Session: ` + phpSessionId.substring(0,8), 'success');
    }
}

function deleteSession(sessionId) {
    if (!confirm('Delete session '+sessionId+'? This removes all traces and memory.')) return;
    fetch('{{ route("harness.api.sessions.delete", "__placeholder__") }}'.replace('__placeholder__', sessionId), {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { showToast('Session deleted', 'success'); loadIsolatedSessions(); }
        else { showToast('Delete failed: '+(data.error||''), 'error'); }
    })
    .catch(err => { showToast('Delete failed: '+err.message, 'error'); });
}

function purgeAllSessions() {
    if (!confirm('Purge ALL isolated sessions? This cannot be undone.')) return;
    fetch('{{ route("harness.api.sessions.purge") }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { showToast(data.message, 'success'); loadIsolatedSessions(); }
        else { showToast('Purge failed: '+(data.error||''), 'error'); }
    })
    .catch(err => { showToast('Purge failed: '+err.message, 'error'); });
}

function cleanupOldSessions() {
    if (!confirm('Clean up sessions older than the configured threshold?')) return;
    fetch('{{ route("harness.api.sessions.cleanup") }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { showToast(data.message, 'success'); loadIsolatedSessions(); }
        else { showToast('Cleanup failed: '+(data.error||''), 'error'); }
    })
    .catch(err => { showToast('Cleanup failed: '+err.message, 'error'); });
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const units = ['B','KB','MB','GB'];
    const power = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, power)).toFixed(2) + ' ' + units[power];
}

// Auto-load sessions on page load
loadIsolatedSessions();
</script>
</body>
</html>
