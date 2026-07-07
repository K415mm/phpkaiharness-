<?php

use Laravel\Ai\Ai;

require __DIR__.'/bootstrap.php';

$report = getReport($dbPath);
$stats = $report ? $report->getStats() : null;
$sessions = $report ? $report->getSessions(50) : [];
$daily = $report ? $report->getDailyStats(7) : [];
$empty = $stats === null;

$laravelAiConnections = [];
if (class_exists(Ai::class) && function_exists('app') && app()->bound('config')) {
    $laravelAiConnections = array_keys(config('ai.providers', []));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>phpkaiharness — Telemetry Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="phpkaiharness AI Agent Telemetry Dashboard">
    <!-- HUD Core CSS -->
    <link href="assets/css/vendor.min.css" rel="stylesheet">
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/plugins/bootstrap-icons/font/bootstrap-icons.css">
    <!-- ApexCharts -->
    <link rel="stylesheet" href="assets/plugins/apexcharts/dist/apexcharts.min.css">
    <style>
        /* ── Custom Harness Overrides ── */
        .harness-trace-panel {
            min-height: 250px;
            background: rgba(0,0,0,.25);
            border-radius: 6px;
            padding: 10px;
        }
        .collapsed { display:none !important; }
        .badge-executor   { background: rgba(66,135,245,.2); color:#82b4ff; }
        .badge-fast-path  { background: rgba(52,199,89,.2);  color:#5edb8e; }
        .badge-cache      { background: rgba(255,179,0,.2);  color:#ffd055; }
        .run-output-box {
            background: rgba(0,0,0,.35); border: 1px solid rgba(255,255,255,.06);
            border-radius:6px; padding:1rem; font-family:monospace; font-size:.82rem;
            white-space:pre-wrap; min-height:50px; display:none;
        }
        .run-output-box.visible { display:block; }
        /* Slim scrollbar */
        ::-webkit-scrollbar { width:5px; height:5px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:rgba(255,255,255,.15); border-radius:4px; }

        /* ── Workflow Graph Design ── */
        .workflow-graph-wrapper {
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
            overflow-x: auto;
            display: flex;
            align-items: center;
            scroll-behavior: smooth;
        }
        .workflow-node {
            flex: 0 0 155px;
            background: rgba(18, 18, 28, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.25s ease-in-out;
            box-shadow: 0 4px 10px rgba(0,0,0,0.4);
            position: relative;
        }
        .workflow-node.disabled-node {
            border-color: rgba(239, 68, 68, 0.35) !important;
            background: rgba(40, 20, 20, 0.4) !important;
            opacity: 0.65;
        }
        .workflow-node.disabled-node .workflow-node-icon {
            color: #ef4444 !important;
        }
        .workflow-node.disabled-node:hover {
            opacity: 0.9;
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.5) !important;
        }
        .workflow-node.idle-node {
            border-color: rgba(16, 185, 129, 0.3) !important;
            background: rgba(20, 35, 25, 0.3) !important;
            opacity: 0.75;
        }
        .workflow-node.idle-node .workflow-node-icon {
            color: rgba(16, 185, 129, 0.6) !important;
        }
        .workflow-node.idle-node:hover {
            opacity: 0.95;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4) !important;
        }
        .workflow-node:hover {
            transform: translateY(-4px);
            border-color: var(--node-color, var(--bs-theme));
            box-shadow: 0 6px 20px rgba(0,0,0,0.6), 0 0 8px var(--node-color, var(--bs-theme));
        }
        .workflow-node.active {
            border-color: var(--node-color, var(--bs-theme)) !important;
            box-shadow: 0 0 12px var(--node-color, var(--bs-theme));
            background: rgba(25, 25, 38, 0.95);
        }
        .workflow-node-icon {
            font-size: 1.25rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .workflow-node-title {
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 3px;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
        }
        .workflow-node-meta {
            font-size: 9px;
            color: rgba(255,255,255,0.45);
        }
        .workflow-node-metrics {
            margin-top: 6px;
            padding-top: 5px;
            border-top: 1px solid rgba(255,255,255,0.06);
            display: flex;
            justify-content: space-between;
            font-size: 9px;
        }
        .workflow-node-metrics span:first-child {
            color: rgba(255,255,255,0.4);
        }
        .workflow-connector-line {
            flex: 0 0 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        .workflow-connector-svg {
            overflow: visible;
        }
        .animated-pulse-dot {
            animation: pulse-move 2.5s linear infinite;
        }
        @keyframes pulse-move {
            0% { cx: 0; opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { cx: 60; opacity: 0; }
        }
        .workflow-details-box {
            position: relative;
        }
        .trace-label { color: var(--bs-theme); font-weight:bold; margin-bottom:.15rem; }
    </style>
</head>
<body>
<!-- BEGIN #app -->
<div id="app" class="app">

    <!-- ═══════════════════════════════════════════ HEADER -->
    <div id="header" class="app-header">
        <!-- desktop toggler -->
        <div class="desktop-toggler">
            <button type="button" class="menu-toggler"
                data-toggle-class="app-sidebar-collapsed"
                data-dismiss-class="app-sidebar-toggled"
                data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <!-- mobile toggler -->
        <div class="mobile-toggler">
            <button type="button" class="menu-toggler"
                data-toggle-class="app-sidebar-mobile-toggled"
                data-toggle-target=".app">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </button>
        </div>
        <!-- brand -->
        <div class="brand">
            <a href="/" class="brand-logo">
                <span class="brand-img">
                    <span class="brand-img-text text-theme">K</span>
                </span>
                <span class="brand-text">phpkaiharness</span>
            </a>
        </div>
        <!-- header right menu -->
        <div class="menu">
            <div class="menu-item">
                <a href="/" class="menu-link text-theme fw-bold">
                    <div class="menu-icon"><i class="bi bi-cpu nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Dashboard</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="/config" class="menu-link">
                    <div class="menu-icon"><i class="bi bi-sliders nav-icon"></i></div>
                    <div class="menu-text d-none d-sm-block">Configuration</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="#" class="menu-link" onclick="location.reload()">
                    <div class="menu-icon"><i class="bi bi-arrow-clockwise nav-icon"></i></div>
                </a>
            </div>
        </div>
    </div>
    <!-- END header -->

    <!-- ═══════════════════════════════════════════ SIDEBAR -->
    <div id="sidebar" class="app-sidebar">
        <div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
            <div class="menu">
                <div class="menu-header">phpkaiharness</div>
                <div class="menu-item active">
                    <a href="/" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-cpu"></i></span>
                        <span class="menu-text">Dashboard</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="/config" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-sliders"></i></span>
                        <span class="menu-text">Configuration</span>
                    </a>
                </div>
                <div class="menu-divider"></div>
                <div class="menu-header">Telemetry</div>
                <div class="menu-item">
                    <a href="#stats" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-bar-chart-line"></i></span>
                        <span class="menu-text">Statistics</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#sessions" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-list-ul"></i></span>
                        <span class="menu-text">Sessions</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="#trace" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-diagram-3"></i></span>
                        <span class="menu-text">Trace Viewer</span>
                    </a>
                </div>
                <div class="menu-divider"></div>
                <div class="menu-header">Agent</div>
                <div class="menu-item">
                    <a href="#run-agent" class="menu-link">
                        <span class="menu-icon"><i class="bi bi-play-circle"></i></span>
                        <span class="menu-text">Run Agent</span>
                    </a>
                </div>
            </div>
            <div class="p-3 px-4 mt-auto">
                <div class="small text-inverse text-opacity-50 mb-2">phpkaiharness v0.1</div>
                <div class="small text-inverse text-opacity-50">
                    <i class="bi bi-hdd-fill me-1 text-theme"></i>
                    DB: <?= htmlspecialchars(basename($dbPath)) ?>
                </div>
            </div>
        </div>
    </div>
    <!-- END sidebar -->

    <button class="app-sidebar-mobile-backdrop"
        data-toggle-target=".app"
        data-toggle-class="app-sidebar-mobile-toggled"></button>

    <!-- ═══════════════════════════════════════════ CONTENT -->
    <div id="content" class="app-content">

        <?php if ($empty) { ?>
        <!-- ── Empty State ── -->
        <div class="row justify-content-center mt-5">
            <div class="col-xl-6 text-center">
                <div class="card">
                    <div class="card-body py-5">
                        <i class="bi bi-cpu display-3 text-theme opacity-50 mb-3 d-block"></i>
                        <h4 class="text-inverse mb-2">No Sessions Logged Yet</h4>
                        <p class="text-inverse text-opacity-50 mb-4">
                            Execute the harness CLI to generate telemetry traces.
                        </p>
                        <code class="d-inline-block bg-black bg-opacity-50 rounded px-4 py-2 text-theme fs-12px">
                            php bin/harness run "Check DNS records for google.com"
                        </code>
                    </div>
                    <div class="card-arrow">
                        <div class="card-arrow-top-left"></div>
                        <div class="card-arrow-top-right"></div>
                        <div class="card-arrow-bottom-left"></div>
                        <div class="card-arrow-bottom-right"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php } else {
            $totalTokens = ($stats['total_prompt_tokens'] ?? 0) + ($stats['total_completion_tokens'] ?? 0);
            ?>

        <!-- ── STAT CARDS ── -->
        <div id="stats" class="row mb-3">
            <?php
                $statCards = [
                    ['label' => 'Total Sessions',     'value' => number_format($stats['total_sessions'] ?? 0),       'icon' => 'bi-cpu',               'type' => 'bar'],
                    ['label' => 'LLM Calls',          'value' => number_format($stats['total_llm_calls'] ?? 0),       'icon' => 'bi-stars',             'type' => 'line'],
                    ['label' => 'Tool Executions',    'value' => number_format($stats['total_tool_calls'] ?? 0),      'icon' => 'bi-tools',             'type' => 'bar'],
                    ['label' => 'Avg Duration',       'value' => number_format($stats['avg_duration_ms'] ?? 0).'ms',  'icon' => 'bi-stopwatch',         'type' => 'line'],
                    ['label' => 'Prompt Tokens',      'value' => number_format($stats['total_prompt_tokens'] ?? 0),   'icon' => 'bi-file-text',         'type' => 'bar'],
                    ['label' => 'Completion Tokens',  'value' => number_format($stats['total_completion_tokens'] ?? 0), 'icon' => 'bi-chat-square-text', 'type' => 'line'],
                ];
            foreach ($statCards as $sc) { ?>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3">
                            <span class="flex-grow-1 text-uppercase text-inverse text-opacity-50"><?= $sc['label'] ?></span>
                            <i class="bi <?= $sc['icon'] ?> text-theme"></i>
                        </div>
                        <div class="row align-items-center mb-2">
                            <div class="col-12">
                                <h3 class="mb-0"><?= $sc['value'] ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <div class="card-arrow-top-left"></div>
                        <div class="card-arrow-top-right"></div>
                        <div class="card-arrow-bottom-left"></div>
                        <div class="card-arrow-bottom-right"></div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- ── SESSIONS + TRACE VIEWER ── -->
        <div class="row mb-3">
            <!-- Sessions Table -->
            <div class="col-12 mb-3" id="sessions">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3">
                            <span class="flex-grow-1">RECENT SESSIONS</span>
                            <span class="badge bg-theme text-black ms-2"><?= count($sessions) ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" id="sessionTable">
                                <thead>
                                    <tr class="text-inverse text-opacity-50 fw-bold small">
                                        <th>ID</th>
                                        <th>Method</th>
                                        <th style="max-width:220px">Prompt</th>
                                        <th>Duration</th>
                                        <th>LLM</th>
                                        <th>Tools</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $s) {
                                        $m = $s['method'];
                                        $badgeCls = str_contains($m, 'fast') ? 'badge-fast-path' : (str_contains($m, 'cache') ? 'badge-cache' : 'badge-executor');
                                        ?>
                                    <tr style="cursor:pointer"
                                        onclick="loadSessionTrace('<?= htmlspecialchars($s['id']) ?>', this)"
                                        id="row-<?= htmlspecialchars(substr($s['id'], 0, 8)) ?>">
                                        <td class="font-monospace fs-11px"><?= htmlspecialchars(substr($s['id'], 0, 8)) ?>…</td>
                                        <td>
                                            <span class="badge fw-bold <?= $badgeCls ?>">
                                                <?= htmlspecialchars($m) ?>
                                            </span>
                                        </td>
                                        <td class="text-truncate" style="max-width:200px"
                                            title="<?= htmlspecialchars($s['prompt']) ?>">
                                            <?= htmlspecialchars($s['prompt']) ?>
                                        </td>
                                        <td class="fs-11px"><?= number_format($s['total_duration_ms'] ?? 0) ?>ms</td>
                                        <td class="text-center"><?= $s['llm_calls'] ?? 0 ?></td>
                                        <td class="text-center"><?= $s['tool_calls'] ?? 0 ?></td>
                                        <td class="fs-10px text-inverse text-opacity-50">
                                            <?= htmlspecialchars($s['created_at']) ?>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-arrow">
                        <div class="card-arrow-top-left"></div>
                        <div class="card-arrow-top-right"></div>
                        <div class="card-arrow-bottom-left"></div>
                        <div class="card-arrow-bottom-right"></div>
                    </div>
                </div>
            </div>

            <!-- Trace Viewer -->
            <div class="col-12" id="trace">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex fw-bold small mb-3">
                            <span class="flex-grow-1">EXECUTION TRACE VIEWER</span>
                            <span class="badge bg-theme text-black" id="traceMethodBadge" style="display:none"></span>
                        </div>

                        <div class="harness-trace-panel" id="tracePanel">
                            <!-- Empty placeholder -->
                            <div id="traceEmpty" class="d-flex flex-column align-items-center justify-content-center py-5 text-inverse text-opacity-50">
                                <i class="bi bi-diagram-3 display-4 mb-3 opacity-25"></i>
                                <p class="mb-0 text-center small">Select any session to view its<br>detailed execution trace</p>
                            </div>

                            <div class="collapsed" id="traceContent">
                                <div class="border-bottom border-inverse border-opacity-15 pb-2 mb-3">
                                    <div class="fw-bold text-inverse small" id="traceSessionId">—</div>
                                    <div class="fs-10px text-inverse text-opacity-50" id="traceSessionTime">—</div>
                                </div>
                                <!-- Horizontal workflow graph -->
                                <div class="workflow-graph-wrapper mb-3" id="workflowGraph"></div>
                                
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
                    <div class="card-arrow">
                        <div class="card-arrow-top-left"></div>
                        <div class="card-arrow-top-right"></div>
                        <div class="card-arrow-bottom-left"></div>
                        <div class="card-arrow-bottom-right"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── RUN AGENT ── -->
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
                            <?php if (! empty($laravelAiConnections)) { ?>
                            <div class="col-xl-3 col-md-6" id="connectionGroup" style="display:none">
                                <label class="form-label small text-inverse text-opacity-50">Laravel AI Connection</label>
                                <select class="form-select form-select-sm" id="connectionSelect" onchange="saveSettings();loadModels();">
                                    <?php foreach ($laravelAiConnections as $conn) { ?>
                                    <option value="<?= htmlspecialchars($conn) ?>"><?= htmlspecialchars($conn) ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <?php } ?>
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
                                    <button class="btn btn-sm btn-outline-theme" id="reloadModelsBtn"
                                        onclick="loadModels()" title="Load models from provider">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-inverse text-opacity-50">Prompt</label>
                                <textarea class="form-control form-control-sm" id="promptInput" rows="3"
                                    placeholder="Enter your prompt… e.g. 'What is the reachability status of google.com?'"></textarea>
                            </div>
                            <div class="col-12 d-flex flex-wrap align-items-center gap-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optCache" role="switch">
                                    <label class="form-check-label small" for="optCache">Semantic Caching</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optCompact" role="switch">
                                    <label class="form-check-label small" for="optCompact">Context Compaction</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optGuard" role="switch">
                                    <label class="form-check-label small" for="optGuard">Safety Guardrails</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="optQuantum" role="switch">
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
                    <div class="card-arrow">
                        <div class="card-arrow-top-left"></div>
                        <div class="card-arrow-top-right"></div>
                        <div class="card-arrow-bottom-left"></div>
                        <div class="card-arrow-bottom-right"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php } ?>
    </div>
    <!-- END content -->

</div>
<!-- END #app -->

<!-- Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="harnessToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" data-bs-autohide="true" data-bs-delay="3000">
        <div class="d-flex">
            <div class="toast-body" id="harnessToastBody">Message</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- HUD Core JS -->
let activeTraceNodes = [];

async function loadSessionTrace(sessionId, rowEl) {
    document.querySelectorAll('#sessionTable tr').forEach(r => r.classList.remove('table-active'));
    if (rowEl) { rowEl.classList.add('table-active'); }

    const traceEmpty   = document.getElementById('traceEmpty');
    const traceContent = document.getElementById('traceContent');
    const traceSessionId   = document.getElementById('traceSessionId');
    const traceSessionTime = document.getElementById('traceSessionTime');
    const traceMethodBadge = document.getElementById('traceMethodBadge');
    const workflowGraph    = document.getElementById('workflowGraph');
    const nodeDetailsCard  = document.getElementById('nodeDetailsCard');

    traceEmpty.classList.add('collapsed');
    traceContent.classList.add('collapsed');
    nodeDetailsCard.style.display = 'none';

    try {
        const res  = await fetch(`/api?action=session&id=${sessionId}`);
        const json = await res.json();

        if (!json.success || !json.session) {
            showToast('Failed to retrieve trace data.', 'error');
            traceEmpty.classList.remove('collapsed');
            return;
        }

        const data = json.session;
        traceSessionId.textContent   = `Session ${data.id.substring(0,13)}…`;
        traceSessionTime.textContent = `Captured: ${data.created_at}`;
        traceMethodBadge.textContent = data.method;
        traceMethodBadge.style.display = '';

        const settings = safeParseJSON(data.settings) || {};

        function isEnabled(key, defaultValue = true) {
            if (key === 'compaction') {
                return settings.compaction?.strategy && settings.compaction.strategy !== 'none';
            }
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

        // Define our standard middleware pipeline checks
        const pipelineFeatures = [
            { key: 'bootstrap.enabled', default: true, type: 'bootstrap', title: 'Bootstrap', meta: 'Environment snapshot', icon: 'bi-info-circle', color: '#00d2ff' },
            { key: 'draft_verification.enabled', default: false, type: 'draft_verification', title: 'Draft Verification', meta: 'Fast-draft pass', icon: 'bi-clipboard-check', color: '#10b981' },
            { key: 'ontology.enabled', default: false, type: 'ontology', title: 'Ontology RAG', meta: 'Context Injection', icon: 'bi-diagram-3-fill', color: '#ec4899' },
            { key: 'quantum_harness.enabled', default: false, type: 'quantum', title: 'Quantum Memory', meta: 'Memory envelope injection', icon: 'bi-atom', color: '#8b5cf6' },
            { key: 'optimizer.enabled', default: true, type: 'optimizer', title: 'Optimizer', meta: 'Prompt optimization', icon: 'bi-magic', color: '#a855f7' },
            { key: 'pii_masking.enabled', default: true, type: 'pii_masking', title: 'PII Masking', meta: 'Redacted keys', icon: 'bi-shield-lock-fill', color: '#f43f5e' },
            { key: 'cache.enabled', default: true, type: 'cache', title: 'Semantic Cache', meta: 'Prompt caching', icon: 'bi-lightning-charge-fill', color: '#10b981' },
            { key: 'rate_limiting.enabled', default: true, type: 'rate_limit', title: 'Rate Limiting', meta: 'Call throttle', icon: 'bi-speedometer2', color: '#f59e0b' },
            { key: 'policy_guardrail.enabled', default: false, type: 'policy_guardrail', title: 'Policy Check', meta: 'Gate middleware', icon: 'bi-person-lock', color: '#f97316' }
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
                    payload: { status: 'Feature is active but did not perform changes in this run.' },
                    response: null,
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
                    response: resp
                });
            }
        });

        // 3. Now render loop executions (LLM Calls & Tool Calls)
        details.forEach(step => {
            // Skip the middleware steps we already placed above to avoid duplicates
            if (['bootstrap', 'ontology', 'quantum', 'optimizer', 'pii_masking', 'cache', 'rate_limit', 'policy_guardrail', 'environment_bootstrap', 'context_compression', 'feature_matrix', 'cache_invalidation'].includes(step.type)) {
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
                response: resp
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

        // 4. Post-loop features: Gating, cognitive memory, etc.
        const postFeatures = [
            { key: 'failover.enabled', default: false, type: 'failover', title: 'LLM Failover', meta: 'Retry client checks', icon: 'bi-arrow-repeat', color: '#ef4444' },
            { key: 'budget.enabled', default: false, type: 'budget', title: 'Thinking Budget', meta: 'Token budget gate', icon: 'bi-stopwatch', color: '#ef4444' },
            { key: 'guardrails.enabled', default: false, type: 'guardrail', title: 'Safety Guardrails', meta: 'Safety checker', icon: 'bi-shield-check', color: '#10b981' },
            { key: 'compaction.strategy', default: 'sliding_window', type: 'compaction', title: 'Context Compaction', meta: 'Context shrinker', icon: 'bi-archive-fill', color: '#06b6d4' },
            { key: 'compaction.compression.enabled', default: false, type: 'compression', title: 'Context Compression', meta: 'Comment stripper', icon: 'bi-file-earmark-zip', color: '#10b981' },
            { key: 'cognitive_memory.enabled', default: false, type: 'cognitive_memory', title: 'Cognitive Memory', meta: 'Fact extraction & dedup', icon: 'bi-brain', color: '#8b5cf6' },
            { key: 'quantum_harness.enabled', default: false, type: 'quantum_collapse', title: 'Quantum Memory Collapse', meta: 'Post-flight decomposition', icon: 'bi-atom', color: '#8b5cf6' },
            { key: 'feature_matrix.enabled', default: true, type: 'feature_matrix', title: 'Feature Matrix', meta: 'Resolved config snapshot', icon: 'bi-diagram-2-fill', color: '#00d2ff' }
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
                    payload: { status: 'Feature is active but did not need to run or trigger in this session.' },
                    response: null,
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
            response: { response: data.response || 'No response returned' }
        });

        // Render workflow graph HTML
        let graphHtml = '';
        activeTraceNodes.forEach((node, idx) => {
            let extraClass = '';
            if (node.isDisabled) { extraClass = 'disabled-node'; }
            else if (node.isIdle) { extraClass = 'idle-node'; }

            graphHtml += `
                <div class="workflow-node ${extraClass}" onclick="selectWorkflowNode(${idx})" id="node-${idx}" style="--node-color: ${node.color}">
                    <div class="workflow-node-icon" style="color: ${node.color}"><i class="bi ${node.icon}"></i></div>
                    <div class="workflow-node-title" title="${escHtml(node.title)}">${escHtml(node.title)}</div>
                    <div class="workflow-node-meta" title="${escHtml(node.meta)}">${escHtml(node.meta)}</div>
                    <div class="workflow-node-metrics">
                        <span>${escHtml(node.duration)}</span>
                        <span style="color: var(--node-color); font-weight: bold;">${escHtml(node.tokens)}</span>
                    </div>
                </div>
            `;
            if (idx < activeTraceNodes.length - 1) {
                let connColor = node.color;
                if (node.isDisabled) { connColor = 'rgba(239, 68, 68, 0.4)'; }
                else if (node.isIdle) { connColor = 'rgba(16, 185, 129, 0.4)'; }

                graphHtml += `
                    <div class="workflow-connector-line">
                        <svg width="60" height="20" class="workflow-connector-svg" viewBox="0 0 60 20">
                            <line x1="0" y1="10" x2="60" y2="10" stroke="rgba(255,255,255,0.08)" stroke-width="2" />
                            <circle cx="0" cy="10" r="3" fill="${connColor}" class="animated-pulse-dot" />
                        </svg>
                    </div>
                `;
            }
        });

        workflowGraph.innerHTML = graphHtml;
        traceContent.classList.remove('collapsed');

        // Auto-select the first node (Start) or the first LLM call if one exists
        let selectIdx = 0;
        const firstLlm = activeTraceNodes.findIndex(n => n.type === 'llm_call');
        if (firstLlm !== -1) { selectIdx = firstLlm; }
        selectWorkflowNode(selectIdx);

    } catch (e) {
        console.error(e);
        showToast('Failed to load trace.', 'error');
        traceEmpty.classList.remove('collapsed');
    }
}

function selectWorkflowNode(idx) {
    document.querySelectorAll('.workflow-node').forEach(node => node.classList.remove('active'));
    const activeNodeEl = document.getElementById(`node-${idx}`);
    if (activeNodeEl) { activeNodeEl.classList.add('active'); }

    const node = activeTraceNodes[idx];
    if (!node) { return; }

    const nodeDetailsCard = document.getElementById('nodeDetailsCard');
    const nodeDetailsTitle = document.getElementById('nodeDetailsTitle');
    const nodeDetailsMeta = document.getElementById('nodeDetailsMeta');
    const nodeDetailsContent = document.getElementById('nodeDetailsContent');

    nodeDetailsTitle.textContent = node.title;
    nodeDetailsMeta.textContent = `${node.duration} ${node.tokens ? '· ' + node.tokens : ''}`;
    nodeDetailsCard.style.display = 'block';

    let html = '';
    if (node.type === 'start') {
        html = `
            <div class="trace-label">USER PROMPT</div>
            <div class="bg-black bg-opacity-35 p-3 rounded font-monospace fs-12px whitespace-pre-wrap text-inverse text-opacity-90">${escHtml(node.payload.prompt)}</div>
        `;
    } else if (node.type === 'end') {
        html = `
            <div class="trace-label">AGENT FINAL RESPONSE</div>
            <div class="bg-black bg-opacity-35 p-3 rounded font-monospace fs-12px whitespace-pre-wrap text-inverse text-opacity-90">${escHtml(node.response.response)}</div>
        `;
    } else {
        const payloadStr = node.payload ? JSON.stringify(node.payload, null, 2) : 'No payload recorded';
        const responseStr = node.response ? JSON.stringify(node.response, null, 2) : 'No response recorded';
        html = `
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

function escHtml(s) {
    if (!s) { return ''; }
    if (typeof s !== 'string') { s = String(s); }
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function safeParseJSON(val) {
    if (val === null || val === undefined || val === '') { return null; }
    try { return JSON.parse(val); } catch(e) { return val; }
}

// ──────────────────────────────────────────────────────────
// Run Agent
// ──────────────────────────────────────────────────────────
const defaultUrls   = { ollama:'http://localhost:11434', lmstudio:'http://localhost:1234', openrouter:'https://openrouter.ai', laravel_ai:'' };
const defaultModels = { ollama:'gemma4:12b-it-qat', lmstudio:'lmstudio-community/gemma-2b-it-GGUF', openrouter:'meta-llama/llama-3-8b-instruct', laravel_ai:'gemma4:12b-it-qat' };
let discoveredAgents = [];

function loadSettings() {
    const provider = localStorage.getItem('phk_provider') || 'ollama';
    const url   = localStorage.getItem('phk_url')   || defaultUrls[provider];
    const model = localStorage.getItem('phk_model') || defaultModels[provider];
    const conn  = localStorage.getItem('phk_connection') || '';
    document.getElementById('providerSelect').value = provider;
    document.getElementById('ollamaUrl').value = url;
    document.getElementById('modelSelect').value = model;
    const connEl = document.getElementById('connectionSelect');
    if (connEl && conn) { connEl.value = conn; }
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

function onProviderChange(isInit = false) {
    const provider = document.getElementById('providerSelect').value;
    const urlEl    = document.getElementById('ollamaUrl');
    const modelEl  = document.getElementById('modelSelect');
    const labelEl  = document.getElementById('urlLabel');
    const connGroup = document.getElementById('connectionGroup');
    const urlGroup  = urlEl.closest('.col-xl-3') || urlEl.closest('.col-md-6');

    if (!isInit) {
        urlEl.value   = defaultUrls[provider] || '';
        modelEl.value = defaultModels[provider] || '';
    }
    if (provider === 'laravel_ai') {
        if (connGroup) { connGroup.style.display = ''; }
        if (urlGroup)  { urlGroup.style.display = 'none'; }
    } else {
        if (connGroup) { connGroup.style.display = 'none'; }
        if (urlGroup)  { urlGroup.style.display = ''; }
    }
    const labels = { ollama:'Ollama URL', lmstudio:'LM Studio URL', openrouter:'OpenRouter URL' };
    labelEl.textContent = labels[provider] || 'URL';
    saveSettings();
    loadModels();
}

async function loadAgents() {
    try {
        const r = await fetch('/api?action=agents');
        const d = await r.json();
        if (d.success && d.agents) {
            discoveredAgents = d.agents;
            const sel = document.getElementById('agentSelect');
            sel.innerHTML = '<option value="">Kali WSL Security Assistant (Default)</option>';
            d.agents.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.class;
                opt.textContent = `${a.name} (${a.class})`;
                sel.appendChild(opt);
            });
            const saved = localStorage.getItem('phk_agent') || '';
            if (saved) { sel.value = saved; onAgentChange(); }
        }
    } catch(e) { console.warn('Agents load failed', e); }
}

function onAgentChange() {
    const cls   = document.getElementById('agentSelect').value;
    const agent = discoveredAgents.find(a => a.class === cls);
    if (agent) {
        if (agent.provider) { document.getElementById('providerSelect').value = agent.provider; onProviderChange(true); }
        if (agent.model)    { document.getElementById('modelSelect').value = agent.model; }
        document.getElementById('promptInput').placeholder = `Enter a task for the ${agent.name}…`;
    } else {
        document.getElementById('promptInput').placeholder = "Enter your prompt… e.g. 'What is the reachability status of google.com?'";
    }
    saveSettings();
}

async function loadModels() {
    const provider = document.getElementById('providerSelect').value;
    const url      = document.getElementById('ollamaUrl').value.trim();
    const connEl   = document.getElementById('connectionSelect');
    const conn     = connEl ? connEl.value : '';
    try {
        const r = await fetch(`/api?action=models&provider=${provider}&connection=${conn}&url=${encodeURIComponent(url)}`);
        const d = await r.json();
        if (d.success && d.models && d.models.length) {
            const dl = document.getElementById('modelDatalist');
            dl.innerHTML = '';
            d.models.forEach(m => {
                const o = document.createElement('option');
                o.value = m;
                dl.appendChild(o);
            });
        }
    } catch(e) { console.warn('Models load failed', e); }
}

async function runAgent() {
    const provider  = document.getElementById('providerSelect').value;
    const agent     = document.getElementById('agentSelect').value;
    const url       = document.getElementById('ollamaUrl').value.trim();
    const model     = document.getElementById('modelSelect').value.trim();
    const prompt    = document.getElementById('promptInput').value.trim();
    const connEl    = document.getElementById('connectionSelect');
    const conn      = connEl ? connEl.value : '';
    const cache     = document.getElementById('optCache').checked;
    const compact   = document.getElementById('optCompact').checked;
    const guard     = document.getElementById('optGuard').checked;
    const quantum   = document.getElementById('optQuantum').checked;

    if (!prompt) { showToast('Please enter a prompt', 'error'); return; }
    if (!model)  { showToast('Please enter a model name', 'error'); return; }

    const btn  = document.getElementById('runBtn');
    const out  = document.getElementById('runOutput');
    const meta = document.getElementById('runMeta');

    btn.disabled    = true;
    btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-1"></span> Running…';
    out.className   = 'run-output-box visible';
    out.textContent = `Executing agent loop with ${model}…`;
    meta.textContent = '';
    saveSettings();

    try {
        const start = Date.now();
        const r = await fetch('/api?action=run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ provider, connection:conn, agent, url, model, prompt, cache, compact, guard, quantum }),
        });
        const d = await r.json();
        const elapsed = ((Date.now() - start) / 1000).toFixed(2);
        if (d.success) {
            out.textContent  = d.response;
            meta.textContent = `Session: ${d.sessionId} · ${elapsed}s · ${d.duration_ms}ms`;
            showToast('Agent completed successfully', 'success');
            setTimeout(() => location.reload(), 2500);
        } else {
            out.textContent = '⚠️ Error: ' + d.error;
            showToast('Agent error', 'error');
        }
    } catch(e) {
        out.textContent = '⚠️ Request failed: ' + e.message;
        showToast('Request failed', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i> Run Agent';
    }
}

// ──────────────────────────────────────────────────────────
// Toast
// ──────────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
    const el   = document.getElementById('harnessToast');
    const body = document.getElementById('harnessToastBody');
    body.textContent = msg;
    el.classList.remove('text-bg-success','text-bg-danger','text-bg-secondary');
    if (type === 'success') { el.classList.add('text-bg-success'); }
    else if (type === 'error') { el.classList.add('text-bg-danger'); }
    else { el.classList.add('text-bg-secondary'); }
    bootstrap.Toast.getOrCreateInstance(el).show();
}

// ── Init ──
loadSettings();
</script>
</body>
</html>
