<?php

namespace Phpkaiharness\Support;

use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Session\SessionManager;

/**
 * Evaluates execution trace nodes from a completed session.
 *
 * Shared between the CLI command and the API endpoint so that
 * manual dashboard runs can be evaluated without re-running the prompt.
 */
class TraceEvaluator
{
    /**
     * Extract all trace data for a session from the SQLite store.
     */
    public function extractTrace(SqliteMonitorStore $store, string $sessionId): array
    {
        $pdo = $store->getPdo();

        $stmt = $pdo->prepare('SELECT * FROM harness_sessions WHERE id = ? OR request_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$sessionId, $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $resolvedSessionId = (string) ($session['id'] ?? $sessionId);

        // Fetch all child/sub-sessions (interactions/agent loops) that belong to this session
        $sessionIds = [$resolvedSessionId];
        try {
            $stmt = $pdo->prepare('SELECT id FROM harness_sessions WHERE parent_session_id = ? OR root_session_id = ?');
            $stmt->execute([$resolvedSessionId, $resolvedSessionId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $childId) {
                $sessionIds[] = $childId;
            }
        } catch (\Throwable $e) {
            // Fallback if schema doesn't have parent/root column yet
        }

        $sessionIds = array_values(array_unique($sessionIds));
        $inPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));

        $stmt = $pdo->prepare("SELECT * FROM harness_details WHERE session_id IN ($inPlaceholders) ORDER BY id ASC");
        $stmt->execute($sessionIds);
        $details = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM harness_facts WHERE session_id IN ($inPlaceholders) ORDER BY id ASC");
        $stmt->execute($sessionIds);
        $facts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'session' => $session,
            'details' => $details,
            'facts' => $facts,
        ];
    }

    /**
     * Evaluate every trace node and return a structured assessment.
     */
    public function evaluateNodes(array $trace, string $prompt, string $response, int $totalMs): array
    {
        $details = $trace['details'] ?? [];
        $settings = json_decode($trace['session']['settings'] ?? '{}', true) ?: [];
        $evaluation = [];

        $featureMap = [
            'bootstrap' => 'Environment Bootstrap',
            'draft_verification' => 'Draft Verification',
            'ontology' => 'Ontological Context Injection',
            'quantum' => 'Quantum Memory Envelope',
            'optimizer' => 'Model Prompt Optimizer',
            'pii_masking' => 'PII Masking',
            'cache' => 'Semantic Cache',
            'rate_limit' => 'Rate Limiting',
            'rate_limiting' => 'Rate Limiting',
            'policy_guardrail' => 'Policy Guardrail',
            'llm_call' => 'LLM Generation',
            'tool_call' => 'Tool Execution',
            'guardrail' => 'Safety Guardrails',
            'compaction' => 'Context Compaction',
            'compression' => 'Context Compression',
            'failover' => 'LLM Failover',
            'budget' => 'Thinking Budget Gate',
            'cognitive_memory' => 'Cognitive Memory Extraction',
            'quantum_collapse' => 'Quantum Memory Collapse',
            'quantum_ingest' => 'Quantum Memory Ingestion',
            'feature_matrix' => 'Resolved Feature Matrix',
        ];

        foreach ($details as $detail) {
            $type = $detail['type'];
            $title = $featureMap[$type] ?? ucfirst($type);
            $payload = json_decode($detail['payload'] ?? '{}', true);
            $resp = json_decode($detail['response'] ?? '{}', true);

            $eval = [
                'node_type' => $type,
                'node_title' => $title,
                'name' => $detail['name'] ?? '',
                'duration_ms' => (int) ($detail['duration_ms'] ?? 0),
                'status' => 'PASS',
                'issue' => '',
                'payload' => $payload,
                'response' => $resp,
            ];

            switch ($type) {
                case 'bootstrap':
                    if (empty($resp) && empty($detail['response'])) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Bootstrap produced no environment snapshot data';
                    }
                    break;

                case 'cache':
                    $cacheStatus = $resp['status'] ?? ($resp['result'] ?? '');
                    if (str_contains(strtolower((string) $cacheStatus), 'hit')) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'Cache HIT — response served from cache';
                    } elseif (str_contains(strtolower((string) $cacheStatus), 'miss')) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'Cache MISS — proceeding with full execution';
                    }
                    break;

                case 'optimizer':
                    $optimizedSystem = $resp['optimized_system'] ?? '';
                    $status = $resp['status'] ?? '';
                    if ($status === 'No changes required') {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'Optimizer found no improvements needed';
                    } elseif (empty($optimizedSystem)) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Optimizer returned empty optimized system prompt';
                    }
                    break;

                case 'pii_masking':
                    $redacted = $payload['redacted'] ?? ($resp['redacted'] ?? []);
                    if (empty($redacted)) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'No PII patterns matched in this prompt';
                    }
                    break;

                case 'rate_limit':
                case 'rate_limiting':
                    $allowed = $resp['allowed'] ?? true;
                    if ($allowed === false || $allowed === 'false') {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Rate limit triggered — request was throttled';
                    }
                    break;

                case 'llm_call':
                    $content = $resp['content'] ?? '';
                    $chatResponse = $resp['chat_response'] ?? '';
                    $responseText = $resp['response'] ?? '';
                    $toolCalls = $resp['tool_calls'] ?? [];

                    // Handle raw Chat Completions API response format: choices[0].message.content
                    if (empty($content) && empty($toolCalls) && isset($resp['choices'][0]['message'])) {
                        $choiceMsg = $resp['choices'][0]['message'];
                        $content = $choiceMsg['content'] ?? '';
                        $toolCalls = $choiceMsg['tool_calls'] ?? [];
                    }

                    // Also check 'text' field (Laravel AI SDK format)
                    if (empty($content) && isset($resp['text'])) {
                        $content = $resp['text'];
                    }

                    if (empty($content) && empty($chatResponse) && empty($responseText) && empty($toolCalls)) {
                        $eval['status'] = 'FAIL';
                        $eval['issue'] = 'LLM returned empty content and no tool calls';
                    }
                    if (! empty($toolCalls)) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'LLM requested '.count($toolCalls).' tool call(s)';
                    }
                    break;

                case 'tool_call':
                    $toolResult = $detail['response'] ?? '';
                    if (str_contains($toolResult, '"status":"error"') || str_contains($toolResult, '"status":"blocked"')) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = "Tool '{$detail['name']}' returned error/blocked status";
                    }
                    break;

                case 'guardrail':
                    $decision = $resp['decision'] ?? '';
                    if (str_contains(strtolower($decision), 'blocked')) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Guardrails blocked a tool execution';
                    }
                    break;

                case 'failover':
                    $eval['status'] = 'WARN';
                    $eval['issue'] = 'Failover was triggered — primary client failed';
                    break;

                case 'ontology':
                    $status = $resp['status'] ?? '';
                    $error = $payload['error'] ?? ($resp['error'] ?? '');
                    $injected = $resp['injected_context'] ?? '';
                    if (! empty($error)) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Ontological context injection failed: '.$error;
                    } elseif ($status === 'Skipped' || empty($injected)) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'No ontological context met similarity threshold of '.($payload['similarity_threshold'] ?? 0.30);
                    } else {
                        $eval['status'] = 'PASS';
                        $eval['issue'] = 'Ontological context successfully injected.';
                    }
                    break;

                case 'quantum':
                    $injected = $payload['injected'] ?? false;
                    if (! $injected) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'Quantum memory envelope found no relevant context';
                    }
                    break;

                case 'compaction':
                    $turnsAfter = $resp['turns_after'] ?? 0;
                    $turnsBefore = $payload['turns_before'] ?? 0;
                    if ($turnsBefore === $turnsAfter) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'No compaction needed — conversation within limits';
                    }
                    break;

                case 'draft_verification':
                    if (empty($resp)) {
                        $eval['status'] = 'WARN';
                        $eval['issue'] = 'Draft verification produced no result';
                    }
                    break;

                case 'cognitive_memory':
                    $facts = $trace['facts'] ?? [];
                    if (empty($facts)) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'No memory facts extracted from this session';
                    }
                    break;

                case 'quantum_collapse':
                    $eval['status'] = 'PASS';
                    $eval['issue'] = 'Quantum memory collapse job dispatched for post-flight graph decomposition';
                    break;

                case 'compression':
                    $occurred = $payload['compression_occurred'] ?? false;
                    if ($occurred) {
                        $eval['status'] = 'INFO';
                        $eval['issue'] = 'Successfully compressed prompt context and/or attachments.';
                    } else {
                        $eval['status'] = 'PASS';
                        $eval['issue'] = 'No compression required. Context was below threshold.';
                    }
                    break;

                case 'policy_guardrail':
                    $error = $payload['error'] ?? ($resp['error'] ?? '');
                    if (! empty($error)) {
                        $eval['status'] = 'FAIL';
                        $eval['issue'] = 'Policy check failed or blocked: '.$error;
                    } else {
                        $eval['status'] = 'PASS';
                        $eval['issue'] = 'Policy check passed.';
                    }
                    break;
            }

            $evaluation[] = $eval;
        }

        // Check for features that should have run but didn't appear
        // Use LIVE config so evaluation reflects current settings, not stale snapshot
        $executedTypes = array_column($details, 'type');
        $liveSettings = [];
        if (function_exists('config')) {
            try {
                $liveSettings = config('harness', []) ?: [];
            } catch (\Throwable $e) {
                $liveSettings = $settings; // fall back to snapshot
            }
        } else {
            $liveSettings = $settings;
        }

        $expectedFeatures = [
            'bootstrap' => fn () => $liveSettings['bootstrap']['enabled'] ?? false,
            'optimizer' => fn () => $liveSettings['feature_graph']['nodes']['model_optimizer']['enabled']
                                           ?? ($liveSettings['optimizer']['enabled'] ?? false),
            'pii_masking' => fn () => $liveSettings['pii_masking']['enabled'] ?? false,
            'cache' => fn () => $liveSettings['feature_graph']['nodes']['semantic_cache']['enabled']
                                           ?? ($liveSettings['cache']['enabled'] ?? false),
            'rate_limit' => fn () => $liveSettings['rate_limiting']['enabled'] ?? false,
            'guardrail' => fn () => $liveSettings['feature_graph']['nodes']['guardrails']['enabled']
                                           ?? ($liveSettings['guardrails']['enabled'] ?? false),
            'compaction' => fn () => $liveSettings['feature_graph']['nodes']['context_compactor']['enabled']
                                           ?? (($liveSettings['compaction']['strategy'] ?? 'none') !== 'none'),
            'budget' => fn () => $liveSettings['budget']['enabled'] ?? false,
            'cognitive_memory' => fn () => $liveSettings['feature_graph']['nodes']['cognitive_memory']['enabled']
                                           ?? ($liveSettings['cognitive_memory']['enabled'] ?? false),
            'draft_verification' => fn () => $liveSettings['feature_graph']['nodes']['draft_verification']['enabled']
                                           ?? ($liveSettings['draft_verification']['enabled'] ?? false),
            'quantum' => fn () => $liveSettings['quantum_harness']['enabled'] ?? false,
            'quantum_collapse' => fn () => $liveSettings['quantum_harness']['enabled'] ?? false,
            'ontology' => fn () => $liveSettings['feature_graph']['nodes']['ontology_injection']['enabled']
                                           ?? ($liveSettings['ontology']['enabled'] ?? false),
            'compression' => fn () => $liveSettings['compression']['enabled'] ?? false,
            'policy_guardrail' => fn () => $liveSettings['policy_guardrail']['enabled'] ?? false,
        ];

        foreach ($expectedFeatures as $type => $checker) {
            if (! in_array($type, $executedTypes) && $checker()) {
                $title = $featureMap[$type] ?? ucfirst($type);
                $evaluation[] = [
                    'node_type' => $type,
                    'node_title' => $title,
                    'name' => '',
                    'duration_ms' => 0,
                    'status' => 'WARN',
                    'issue' => 'Feature is enabled in config but no telemetry detail row was recorded for this session',
                    'payload' => null,
                    'response' => null,
                ];
            }
        }

        // ── Session hierarchy assertions ───────────────────────────────────
        $sessionRow = $trace['session'] ?? [];
        if (! empty($sessionRow)) {
            // Parent session linkage check
            if (! empty($sessionRow['parent_session_id']) && $sessionRow['parent_session_id'] === $sessionRow['id']) {
                $evaluation[] = [
                    'node_type' => 'assertion',
                    'node_title' => 'Session Hierarchy',
                    'name' => 'parent_linkage',
                    'duration_ms' => 0,
                    'status' => 'WARN',
                    'issue' => 'Session parent_session_id equals its own id — hierarchy may be misconfigured',
                    'payload' => null,
                    'response' => null,
                ];
            }

            // Status check
            $sessionStatus = $sessionRow['status'] ?? 'completed';
            if ($sessionStatus === 'failed') {
                $evaluation[] = [
                    'node_type' => 'assertion',
                    'node_title' => 'Session Status',
                    'name' => 'status_check',
                    'duration_ms' => 0,
                    'status' => 'FAIL',
                    'issue' => 'Session status is failed: '.($sessionRow['error'] ?? 'unknown error'),
                    'payload' => null,
                    'response' => null,
                ];
            } elseif ($sessionStatus === 'running' || $sessionStatus === 'pending') {
                $evaluation[] = [
                    'node_type' => 'assertion',
                    'node_title' => 'Session Status',
                    'name' => 'status_check',
                    'duration_ms' => 0,
                    'status' => 'WARN',
                    'issue' => 'Session is still '.$sessionStatus.' — trace may be incomplete',
                    'payload' => null,
                    'response' => null,
                ];
            }

            // LLM call presence check (every interaction should have at least one)
            if (! in_array('llm_call', $executedTypes) && ! in_array('cache', $executedTypes)) {
                $evaluation[] = [
                    'node_type' => 'assertion',
                    'node_title' => 'LLM Call Presence',
                    'name' => 'llm_call_check',
                    'duration_ms' => 0,
                    'status' => 'WARN',
                    'issue' => 'No LLM call or cache hit recorded for this session',
                    'payload' => null,
                    'response' => null,
                ];
            }
        }

        // Final response check
        if (empty($response) || str_starts_with($response, 'ERROR') || str_starts_with($response, '⚠️')) {
            $evaluation[] = [
                'node_type' => 'final_response',
                'node_title' => 'Final Response',
                'name' => '',
                'duration_ms' => $totalMs,
                'status' => 'FAIL',
                'issue' => 'Final response is empty or contains an error: '.mb_substr($response, 0, 200),
                'payload' => null,
                'response' => ['response' => $response],
            ];
        } else {
            $evaluation[] = [
                'node_type' => 'final_response',
                'node_title' => 'Final Response',
                'name' => '',
                'duration_ms' => $totalMs,
                'status' => 'PASS',
                'issue' => '',
                'payload' => null,
                'response' => ['response' => mb_substr($response, 0, 500)],
            ];
        }

        return $evaluation;
    }

    /**
     * Build a human-readable debug report string.
     */
    public function buildReport(
        string $sessionId,
        string $prompt,
        string $provider,
        string $model,
        array $trace,
        array $evaluation,
        string $response,
        int $totalMs
    ): string {
        $lines = [];
        $lines[] = '╔══════════════════════════════════════════════════════════════════════╗';
        $lines[] = '║         PHPKAIHARNESS EXECUTION TRACE DEBUG REPORT                   ║';
        $lines[] = '╚══════════════════════════════════════════════════════════════════════╝';
        $lines[] = '';
        $lines[] = 'Generated: '.date('Y-m-d H:i:s');
        $lines[] = "Session ID: {$sessionId}";
        $lines[] = "Provider: {$provider}";
        $lines[] = "Model: {$model}";
        $lines[] = "Total Duration: {$totalMs}ms";
        $lines[] = '';
        $lines[] = '── PROMPT ──────────────────────────────────────────────────────────────';
        $lines[] = $prompt;
        $lines[] = '';
        $lines[] = '── FINAL RESPONSE ─────────────────────────────────────────────────────';
        $lines[] = mb_substr($response, 0, 2000);
        $lines[] = '';
        $lines[] = '── SESSION METADATA ────────────────────────────────────────────────────';
        $session = $trace['session'] ?? [];
        $lines[] = 'Method: '.($session['method'] ?? 'unknown');
        $lines[] = 'Iterations: '.($session['iterations'] ?? 0);
        $lines[] = 'Duration (DB): '.($session['total_duration_ms'] ?? 0).'ms';
        $lines[] = 'Created: '.($session['created_at'] ?? '');
        $lines[] = 'Status: '.($session['status'] ?? 'unknown');
        $lines[] = 'Session Type: '.($session['session_type'] ?? 'unknown');
        $lines[] = 'Parent Session: '.($session['parent_session_id'] ?? 'none');
        $lines[] = 'Root Session: '.($session['root_session_id'] ?? 'none');
        $lines[] = 'Request ID: '.($session['request_id'] ?? 'none');
        $lines[] = 'Interaction Index: '.($session['interaction_index'] ?? 0);
        $lines[] = 'Settings: '.($session['settings'] ?? '{}');
        $lines[] = '';

        $lines[] = '── TRACE NODES ('.count($trace['details'] ?? []).' total) ─────────────────────────────────────';
        foreach ($trace['details'] ?? [] as $i => $detail) {
            $lines[] = '';
            $lines[] = "  [Node #{$i}] type={$detail['type']} name={$detail['name']}";
            $lines[] = "    Duration: {$detail['duration_ms']}ms";
            $lines[] = "    Tokens: ↑{$detail['tokens_prompt']} ↓{$detail['tokens_completion']}";
            $lines[] = '    Payload: '.mb_substr($detail['payload'] ?? '', 0, 500);
            $lines[] = '    Response: '.mb_substr($detail['response'] ?? '', 0, 500);
        }
        $lines[] = '';

        $lines[] = '── MEMORY FACTS ('.count($trace['facts'] ?? []).' total) ──────────────────────────────────────';
        foreach ($trace['facts'] ?? [] as $fact) {
            $lines[] = "  • {$fact['fact']}";
        }
        $lines[] = '';

        $lines[] = '── NODE EVALUATION ────────────────────────────────────────────────────';
        $passCount = 0;
        $failCount = 0;
        $warnCount = 0;
        $skipCount = 0;
        $infoCount = 0;

        foreach ($evaluation as $node) {
            $status = $node['status'];
            $lines[] = '';
            $lines[] = "  [{$status}] {$node['node_type']}: {$node['node_title']}";
            if (! empty($node['name'])) {
                $lines[] = "    Name: {$node['name']}";
            }
            $lines[] = "    Duration: {$node['duration_ms']}ms";
            if (! empty($node['issue'])) {
                $lines[] = "    Issue: {$node['issue']}";
            }
            if (! empty($node['payload'])) {
                $lines[] = '    Payload: '.json_encode($node['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            if (! empty($node['response'])) {
                $lines[] = '    Response: '.json_encode($node['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            match ($status) {
                'PASS' => $passCount++,
                'FAIL' => $failCount++,
                'WARN' => $warnCount++,
                'SKIP' => $skipCount++,
                'INFO' => $infoCount++,
                default => null,
            };
        }

        $lines[] = '';
        $lines[] = '── SUMMARY ────────────────────────────────────────────────────────────';
        $lines[] = '  Total Nodes: '.count($evaluation);
        $lines[] = "  ✓ PASS: {$passCount}";
        $lines[] = "  ✗ FAIL: {$failCount}";
        $lines[] = "  ⚠ WARN: {$warnCount}";
        $lines[] = "  ○ SKIP: {$skipCount}";
        $lines[] = "  ℹ INFO: {$infoCount}";
        $lines[] = '';

        if ($failCount > 0) {
            $lines[] = '⚠ RESULT: FAILURES DETECTED — see FAIL nodes above for details.';
        } elseif ($warnCount > 0) {
            $lines[] = '⚠ RESULT: WARNINGS — execution completed but some nodes need attention.';
        } else {
            $lines[] = '✓ RESULT: ALL CHECKS PASSED — execution trace looks healthy.';
        }
        $lines[] = '';
        $lines[] = '═════════════════════════════════════════════════════════════════════════';

        return implode("\n", $lines);
    }

    /**
     * Evaluate an existing session by ID and return the full report.
     *
     * When session isolation is enabled, the session lives in a per-session
     * monitor.db file managed by SessionManager. This method searches there
     * first and falls back to the global DB so the debug report always works.
     */
    public function evaluateSession(string $sessionId): ?array
    {
        $store = null;
        $dbPath = config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();

        $isolationEnabled = config('harness.session_isolation.enabled', false);
        if ($isolationEnabled && function_exists('app')) {
            try {
                $manager = app(SessionManager::class);
                if ($manager->isEnabled()) {
                    $store = $manager->resolveTraceStore($sessionId);
                }
            } catch (\Throwable $e) {
            }
        }

        $store ??= new SqliteMonitorStore($dbPath);

        $trace = $this->extractTrace($store, $sessionId);

        if (empty($trace['session'])) {
            return null;
        }

        $session = $trace['session'];
        $prompt = $session['prompt'] ?? '';
        $response = $session['response'] ?? '';
        $totalMs = (int) ($session['total_duration_ms'] ?? 0);
        $settings = json_decode($session['settings'] ?? '{}', true) ?: [];
        $provider = $settings['default']['provider'] ?? 'unknown';
        $model = $settings['default']['model'] ?? 'unknown';

        // ── Config Drift Detection ───────────────────────────────────────────
        // Compare the stored config snapshot (at session-start time) against
        // the current live config to surface any feature flag changes.
        $liveConfig = [];
        $configDrift = [];
        if (function_exists('config')) {
            try {
                $liveConfig = config('harness', []) ?: [];
            } catch (\Throwable $e) {
                // config() unavailable outside Laravel
            }
        }

        if (! empty($liveConfig) && ! empty($settings)) {
            $configDrift = $this->detectConfigDrift($settings, $liveConfig);
        }

        $evaluation = $this->evaluateNodes($trace, $prompt, $response, $totalMs);
        $report = $this->buildReport($sessionId, $prompt, $provider, $model, $trace, $evaluation, $response, $totalMs);
        $interactions = [];
        if (! empty($session['parent_session_id'])) {
            $interactions = $store->getInteractionsByParent((string) $session['parent_session_id']);
        } elseif (! empty($session['root_session_id'])) {
            $interactions = $store->getInteractionsByParent((string) $session['root_session_id']);
        }

        return [
            'session_id' => (string) ($session['id'] ?? $sessionId),
            'prompt' => $prompt,
            'response' => $response,
            'provider' => $provider,
            'model' => $model,
            'total_duration_ms' => $totalMs,
            'trace' => $trace,
            'evaluation' => $evaluation,
            'report' => $report,
            'settings_at_run' => $settings,
            'live_config' => $liveConfig,
            'config_drift' => $configDrift,
            'has_config_drift' => ! empty($configDrift),
            'parent_session_id' => $session['parent_session_id'] ?? null,
            'root_session_id' => $session['root_session_id'] ?? null,
            'request_id' => $session['request_id'] ?? null,
            'session_type' => $session['session_type'] ?? 'interaction',
            'status' => $session['status'] ?? (! empty($response) ? 'completed' : 'pending'),
            'error' => $session['error'] ?? null,
            'interaction_index' => (int) ($session['interaction_index'] ?? 0),
            'interactions' => $interactions,
            'summary' => [
                'pass' => count(array_filter($evaluation, fn ($n) => $n['status'] === 'PASS')),
                'fail' => count(array_filter($evaluation, fn ($n) => $n['status'] === 'FAIL')),
                'warn' => count(array_filter($evaluation, fn ($n) => $n['status'] === 'WARN')),
                'skip' => count(array_filter($evaluation, fn ($n) => $n['status'] === 'SKIP')),
                'info' => count(array_filter($evaluation, fn ($n) => $n['status'] === 'INFO')),
            ],
        ];
    }

    /**
     * Detect differences between a stored config snapshot and current live config.
     *
     * Only compares known boolean feature flags for clarity.
     *
     * @return array<int, array{key: string, at_run: mixed, live: mixed}>
     */
    protected function detectConfigDrift(array $stored, array $live): array
    {
        $drift = [];

        // Feature flag paths to compare (stored key => live key, fallback via feature_graph)
        $featureFlags = [
            'failover.enabled' => ['failover', 'enabled'],
            'cache.enabled' => ['cache', 'enabled'],
            'pii_masking.enabled' => ['pii_masking', 'enabled'],
            'rate_limiting.enabled' => ['rate_limiting', 'enabled'],
            'guardrails.enabled' => ['guardrails', 'enabled'],
            'optimizer.enabled' => ['optimizer', 'enabled'],
            'ontology.enabled' => ['ontology', 'enabled'],
            'quantum_harness.enabled' => ['quantum_harness', 'enabled'],
            'cognitive_memory.enabled' => ['cognitive_memory', 'enabled'],
            'draft_verification.enabled' => ['draft_verification', 'enabled'],
            'budget.enabled' => ['budget', 'enabled'],
            'compression.enabled' => ['compression', 'enabled'],
            'bootstrap.enabled' => ['bootstrap', 'enabled'],
            'default.max_iterations' => ['default', 'max_iterations'],
        ];

        foreach ($featureFlags as $label => $path) {
            $storedVal = $stored[$path[0]][$path[1]] ?? null;
            $liveVal = $live[$path[0]][$path[1]] ?? null;

            // Also check feature_graph override for live config
            $nodeName = str_replace(['.enabled', '_harness', '_memory', '_masking', '_limiting', '_verification'], '', $path[0]);
            $graphLiveVal = $live['feature_graph']['nodes'][$nodeName]['enabled'] ?? null;
            if ($graphLiveVal !== null) {
                $liveVal = $graphLiveVal;
            }

            if ($storedVal !== $liveVal && ($storedVal !== null || $liveVal !== null)) {
                $drift[] = [
                    'key' => $label,
                    'at_run' => $storedVal,
                    'live' => $liveVal,
                ];
            }
        }

        return $drift;
    }
}
