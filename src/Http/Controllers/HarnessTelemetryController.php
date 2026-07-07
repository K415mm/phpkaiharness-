<?php

namespace Phpkaiharness\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Ai;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\AgentSelector;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Llm\LlmClientFactory;
use Phpkaiharness\Monitor\MonitorReport;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Optimize\ContextCompactor;
use Phpkaiharness\Optimize\Guardrails;
use Phpkaiharness\Optimize\SemanticCache;
use Phpkaiharness\Session\SessionManager;
use Phpkaiharness\Support\TraceEvaluator;
use Phpkaiharness\Tools\WslCommandTool;

/**
 * Telemetry Dashboard & REST API Controller for phpkaiharness.
 *
 * Reads directly from the harness SQLite database.
 */
class HarnessTelemetryController extends Controller
{
    protected MonitorReport $report;

    protected ?SessionManager $sessionManager = null;

    protected bool $isolationEnabled = false;

    public function __construct()
    {
        $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
        $this->report = new MonitorReport($dbPath);

        // Check if session isolation is enabled — if so, per-session monitor.db files
        // contain the actual session data and the global monitor.db is stale.
        try {
            $this->sessionManager = app(SessionManager::class);
            $this->isolationEnabled = $this->sessionManager->isEnabled();
        } catch (\Throwable $e) {
            // SessionManager not available — fall back to global DB
        }
    }

    /**
     * Render the telemetry dashboard HTML view.
     *
     * GET /harness/dashboard
     */
    public function dashboard(): Response
    {
        $stats = $this->report->getStats();
        $sessions = $this->report->getSessions(50);
        $dailyStats = $this->report->getDailyStats(7);

        // When session isolation is enabled, aggregate from per-session monitor.db files
        if ($this->isolationEnabled && $this->sessionManager) {
            $isolatedSessions = $this->sessionManager->collectAllSessions(50);
            if (! empty($isolatedSessions)) {
                $byId = collect($isolatedSessions)->keyBy('id');
                foreach ($sessions as $s) {
                    if (! $byId->has($s['id'])) {
                        $byId->put($s['id'], $s);
                    }
                }
                $sessions = $byId->sortByDesc('created_at')->take(50)->values()->toArray();
                $stats['total_sessions'] = max($stats['total_sessions'], count($isolatedSessions));
            }
            // If no isolated sessions, $sessions already contains global monitor.db data
        }

        $config = config('harness');

        return response()->view('harness::dashboard', compact('stats', 'sessions', 'dailyStats', 'config'));
    }

    /**
     * Return aggregated analytics statistics as JSON.
     *
     * GET /harness/api/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->report->getStats();
        $daily = $this->report->getDailyStats(7);

        // When session isolation is enabled, aggregate stats from per-session DBs
        if ($this->isolationEnabled && $this->sessionManager) {
            $isolatedSessions = $this->sessionManager->collectAllSessions(200);
            if (! empty($isolatedSessions)) {
                $stats['total_sessions'] = max($stats['total_sessions'], count($isolatedSessions));
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'aggregated' => $stats,
                'daily' => $daily,
            ],
        ]);
    }

    /**
     * Return paginated list of sessions.
     *
     * GET /harness/api/sessions?limit=50&offset=0
     */
    public function sessions(Request $request): JsonResponse
    {
        $processed = $this->getProcessedSessions($request);

        if ($request->has('draw')) {
            return response()->json([
                'draw' => $processed['draw'],
                'recordsTotal' => $processed['recordsTotal'],
                'recordsFiltered' => $processed['recordsFiltered'],
                'data' => $processed['data'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $processed['data'],
                'pagination' => [
                    'total' => $processed['recordsFiltered'],
                    'limit' => min($request->integer('limit', 50), 200),
                    'offset' => $request->integer('offset', 0),
                ],
            ],
        ]);
    }

    /**
     * Parse DataTables parameters and retrieve/process trace sessions.
     */
    protected function getProcessedSessions(Request $request): array
    {
        $draw = $request->integer('draw', 0);
        $start = $request->integer('start', $request->integer('offset', 0));
        $length = $request->integer('length', min($request->integer('limit', 50), 200));
        $searchVal = $request->input('search.value');
        $orderColIdx = $request->integer('order.0.column', -1);
        $orderDir = $request->input('order.0.dir', 'desc');

        $columns = [
            0 => 'id',
            1 => 'php_session_id',
            2 => 'method',
            3 => 'prompt',
            4 => 'total_duration_ms',
            5 => 'llm_calls',
            6 => 'tool_calls',
            7 => 'created_at',
        ];
        $sortColumn = ($orderColIdx >= 0 && isset($columns[$orderColIdx])) ? $columns[$orderColIdx] : 'created_at';
        $phpSessionId = $request->query('php_session_id');

        if ($this->isolationEnabled && $this->sessionManager) {
            if (! empty($phpSessionId)) {
                $isolatedSessions = [];
                $dir = storage_path('app/phpkaiharness/sessions/'.basename($phpSessionId));
                if (File::isDirectory($dir)) {
                    $monitorDb = $dir.DIRECTORY_SEPARATOR.'monitor.db';
                    if (File::exists($monitorDb) && (int) File::size($monitorDb) > 0) {
                        try {
                            $pdo = new \PDO('sqlite:'.$monitorDb);
                            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                            $rows = $pdo->query(
                                'SELECT s.*,
                                        COALESCE(SUM(d.tokens_prompt),0)     AS tokens_prompt,
                                        COALESCE(SUM(d.tokens_completion),0) AS tokens_completion,
                                        COUNT(CASE WHEN d.type=\'llm_call\'  THEN 1 END) AS llm_calls,
                                        COUNT(CASE WHEN d.type=\'tool_call\' THEN 1 END) AS tool_calls
                                 FROM harness_sessions s
                                 LEFT JOIN harness_details d ON (
                                     d.session_id = s.id
                                     OR d.session_id IN (SELECT id FROM harness_sessions WHERE parent_session_id = s.id OR root_session_id = s.id)
                                 )
                                 GROUP BY s.id
                                 ORDER BY s.created_at DESC'
                            )->fetchAll(\PDO::FETCH_ASSOC);

                            foreach ($rows as $row) {
                                $row['php_session_id'] = basename($dir);
                                $isolatedSessions[] = $row;
                            }
                            $pdo = null;
                        } catch (\Throwable $e) {
                        }
                    }
                }
            } else {
                $isolatedSessions = $this->sessionManager->collectAllSessions(1000);
            }

            // Fallback: if no isolated sessions found, use global monitor.db data
            if (empty($isolatedSessions)) {
                $isolatedSessions = $this->report->getSessions(1000, 0);
                foreach ($isolatedSessions as &$row) {
                    $row['php_session_id'] = $row['php_session_id'] ?? 'global';
                }
                unset($row);
            }

            $totalRecords = count($isolatedSessions);
            $sessionsCollection = collect($isolatedSessions);

            if (! empty($searchVal)) {
                $sessionsCollection = $sessionsCollection->filter(function ($s) use ($searchVal) {
                    return str_contains(strtolower($s['id'] ?? ''), strtolower($searchVal))
                        || str_contains(strtolower($s['php_session_id'] ?? ''), strtolower($searchVal))
                        || str_contains(strtolower($s['method'] ?? ''), strtolower($searchVal))
                        || str_contains(strtolower($s['prompt'] ?? ''), strtolower($searchVal));
                });
            }

            if (strtolower($orderDir) === 'asc') {
                $sessionsCollection = $sessionsCollection->sortBy($sortColumn);
            } else {
                $sessionsCollection = $sessionsCollection->sortByDesc($sortColumn);
            }

            $filteredCount = $sessionsCollection->count();
            $paginatedSessions = $sessionsCollection->slice($start, $length)->values()->toArray();
        } else {
            $pdo = $this->report->getPdo();
            $query = 'SELECT s.*,
                             COALESCE(SUM(d.tokens_prompt),0)     AS tokens_prompt,
                             COALESCE(SUM(d.tokens_completion),0) AS tokens_completion,
                             COUNT(CASE WHEN d.type=\'llm_call\'  THEN 1 END) AS llm_calls,
                             COUNT(CASE WHEN d.type=\'tool_call\' THEN 1 END) AS tool_calls
                      FROM harness_sessions s
                      LEFT JOIN harness_details d ON d.session_id = s.id';

            $whereClauses = [];
            $params = [];

            if (! empty($phpSessionId)) {
                $whereClauses[] = "COALESCE(s.root_session_id, 'global') = :php_session_id";
                $params[':php_session_id'] = $phpSessionId;
            }

            if (! empty($searchVal)) {
                $whereClauses[] = '(s.id LIKE :search OR s.method LIKE :search OR s.prompt LIKE :search)';
                $params[':search'] = '%'.$searchVal.'%';
            }

            if (! empty($whereClauses)) {
                $query .= ' WHERE '.implode(' AND ', $whereClauses);
            }

            $query .= ' GROUP BY s.id';

            $allowedSortColumns = ['id', 'method', 'prompt', 'total_duration_ms', 'llm_calls', 'tool_calls', 'created_at'];
            $sort = 's.created_at';
            if ($sortColumn && in_array($sortColumn, $allowedSortColumns)) {
                if ($sortColumn === 'llm_calls' || $sortColumn === 'tool_calls') {
                    $sort = $sortColumn;
                } else {
                    $sort = 's.'.$sortColumn;
                }
            }
            $dir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';
            $query .= " ORDER BY {$sort} {$dir}";

            // Count total matching records before pagination
            $countQuery = "SELECT COUNT(*) FROM ($query)";
            $countStmt = $pdo->prepare($countQuery);
            foreach ($params as $k => $v) {
                $countStmt->bindValue($k, $v, \PDO::PARAM_STR);
            }
            $countStmt->execute();
            $filteredCount = (int) $countStmt->fetchColumn();

            // Get total count
            $totalCountQuery = 'SELECT COUNT(*) FROM harness_sessions';
            if (! empty($phpSessionId)) {
                $totalCountQuery .= " WHERE COALESCE(root_session_id, 'global') = :php_session_id";
            }
            $totalStmt = $pdo->prepare($totalCountQuery);
            if (! empty($phpSessionId)) {
                $totalStmt->bindValue(':php_session_id', $phpSessionId, \PDO::PARAM_STR);
            }
            $totalStmt->execute();
            $totalRecords = (int) $totalStmt->fetchColumn();

            $query .= ' LIMIT :limit OFFSET :offset';
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, \PDO::PARAM_STR);
            }
            $stmt->execute();
            $paginatedSessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return [
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredCount,
            'data' => $paginatedSessions,
        ];
    }

    /**
     * Return detailed information for a single session including all LLM/tool traces.
     *
     * GET /harness/api/sessions/{id}
     */
    public function show(string $id): JsonResponse
    {
        $session = null;

        // When session isolation is enabled, find the per-session monitor.db
        if ($this->isolationEnabled && $this->sessionManager) {
            $perSessionDb = $this->sessionManager->findMonitorDbForSession($id);
            if ($perSessionDb) {
                $report = new MonitorReport($perSessionDb);
                $session = $report->getSession($id);
            }
        }

        // Fall back to global monitor.db
        if (! $session) {
            $session = $this->report->getSession($id);
        }

        if (! $session) {
            return response()->json([
                'success' => false,
                'error' => "Session '{$id}' not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    /**
     * Catch-all API Endpoint for AJAX dashboard.
     * Handles ?action=stats, ?action=sessions, ?action=session, ?action=run, ?action=agents, ?action=models
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->query('action', '');

        if ($action === 'stats') {
            $stats = $this->report->getStats();
            $daily = $this->report->getDailyStats(7);

            // When session isolation is enabled, aggregate stats from per-session DBs
            if ($this->isolationEnabled && $this->sessionManager) {
                $isolatedSessions = $this->sessionManager->collectAllSessions(200);
                if (! empty($isolatedSessions)) {
                    $stats['total_sessions'] = max($stats['total_sessions'], count($isolatedSessions));
                }
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'daily' => $daily,
            ]);
        }

        if ($action === 'sessions') {
            $processed = $this->getProcessedSessions($request);

            if ($request->has('draw')) {
                return response()->json([
                    'draw' => $processed['draw'],
                    'recordsTotal' => $processed['recordsTotal'],
                    'recordsFiltered' => $processed['recordsFiltered'],
                    'data' => $processed['data'],
                ]);
            }

            return response()->json([
                'success' => true,
                'sessions' => $processed['data'],
                'total' => $processed['recordsFiltered'],
            ]);
        }

        if ($action === 'main_sessions') {
            $draw = $request->integer('draw', 0);
            $start = $request->integer('start', 0);
            $length = $request->integer('length', 10);
            $searchVal = $request->input('search.value');
            $orderColIdx = $request->integer('order.0.column', -1);
            $orderDir = $request->input('order.0.dir', 'desc');

            $methodFilter = $request->input('method');
            $statusFilter = $request->input('status');
            $minLlmFilter = $request->input('min_llm');
            $minToolFilter = $request->input('min_tool');

            $minLlmVal = ($minLlmFilter !== null && $minLlmFilter !== '') ? (int) $minLlmFilter : null;
            $minToolVal = ($minToolFilter !== null && $minToolFilter !== '') ? (int) $minToolFilter : null;

            $columns = [
                0 => 'php_session_id',
                1 => 'sub_session_count',
                2 => 'total_duration',
                3 => 'llm_calls',
                4 => 'tool_calls',
                5 => 'total_prompt_tokens',
                6 => 'last_active',
            ];
            $sortColumn = ($orderColIdx >= 0 && isset($columns[$orderColIdx])) ? $columns[$orderColIdx] : 'last_active';

            if ($this->isolationEnabled && $this->sessionManager) {
                $mainSessions = $this->sessionManager->collectMainSessions(1000, $methodFilter, $statusFilter, $minLlmVal, $minToolVal);
            } else {
                $mainSessions = [];
                $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
                if (file_exists($dbPath)) {
                    try {
                        $pdo = new \PDO('sqlite:'.$dbPath);
                        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                        // We fetch all rows grouped by root_session_id
                        $rows = $pdo->query(
                            'SELECT 
                                COALESCE(root_session_id, \'global\') as php_session_id,
                                COUNT(id) as sub_session_count,
                                MAX(created_at) as last_active,
                                SUM(total_duration_ms) as total_duration
                             FROM harness_sessions
                             GROUP BY COALESCE(root_session_id, \'global\')'
                        )->fetchAll(\PDO::FETCH_ASSOC);

                        foreach ($rows as $row) {
                            $phpSess = $row['php_session_id'];

                            // Find all sub-session IDs under this main session
                            $subSessionsStmt = $pdo->prepare(
                                "SELECT id, method, status, total_duration_ms, created_at 
                                 FROM harness_sessions 
                                 WHERE COALESCE(root_session_id, 'global') = ?"
                            );
                            $subSessionsStmt->execute([$phpSess]);
                            $subSessions = $subSessionsStmt->fetchAll(\PDO::FETCH_ASSOC);

                            // Load detail stats for each sub-session
                            $matchingSubSessions = [];
                            foreach ($subSessions as $sub) {
                                $detailsStmt = $pdo->prepare(
                                    "SELECT 
                                        COALESCE(SUM(tokens_prompt), 0) as prompt,
                                        COALESCE(SUM(tokens_completion), 0) as completion,
                                        COUNT(CASE WHEN type = 'llm_call' THEN 1 END) as llms,
                                        COUNT(CASE WHEN type = 'tool_call' THEN 1 END) as tools
                                     FROM harness_details 
                                     WHERE session_id = ?"
                                );
                                $detailsStmt->execute([$sub['id']]);
                                $detailsInfo = $detailsStmt->fetch(\PDO::FETCH_ASSOC);

                                $sub['prompt_tokens'] = $detailsInfo ? (int) $detailsInfo['prompt'] : 0;
                                $sub['completion_tokens'] = $detailsInfo ? (int) $detailsInfo['completion'] : 0;
                                $sub['llms'] = $detailsInfo ? (int) $detailsInfo['llms'] : 0;
                                $sub['tools'] = $detailsInfo ? (int) $detailsInfo['tools'] : 0;

                                // Filter sub-sessions
                                if ($methodFilter && ($sub['method'] ?? '') !== $methodFilter) {
                                    continue;
                                }
                                if ($statusFilter && ($sub['status'] ?? '') !== $statusFilter) {
                                    continue;
                                }
                                if ($minLlmVal !== null && $sub['llms'] < $minLlmVal) {
                                    continue;
                                }
                                if ($minToolVal !== null && $sub['tools'] < $minToolVal) {
                                    continue;
                                }

                                $matchingSubSessions[] = $sub;
                            }

                            if (count($matchingSubSessions) > 0) {
                                $row['sub_session_count'] = count($matchingSubSessions);
                                $row['last_active'] = max(array_column($matchingSubSessions, 'created_at'));
                                $row['total_duration'] = array_sum(array_column($matchingSubSessions, 'total_duration_ms'));
                                $row['total_prompt_tokens'] = array_sum(array_column($matchingSubSessions, 'prompt_tokens'));
                                $row['total_completion_tokens'] = array_sum(array_column($matchingSubSessions, 'completion_tokens'));
                                $row['llm_calls'] = array_sum(array_column($matchingSubSessions, 'llms'));
                                $row['tool_calls'] = array_sum(array_column($matchingSubSessions, 'tools'));

                                $mainSessions[] = $row;
                            }
                        }
                        $pdo = null;
                    } catch (\Throwable $e) {
                    }
                }
            }

            $totalRecords = count($mainSessions);
            $collection = collect($mainSessions);

            if (! empty($searchVal)) {
                $collection = $collection->filter(function ($s) use ($searchVal) {
                    return str_contains(strtolower($s['php_session_id'] ?? ''), strtolower($searchVal));
                });
            }

            if (strtolower($orderDir) === 'asc') {
                $collection = $collection->sortBy($sortColumn);
            } else {
                $collection = $collection->sortByDesc($sortColumn);
            }

            $filteredCount = $collection->count();
            $paginated = $collection->slice($start, $length)->values()->toArray();

            if ($request->has('draw')) {
                return response()->json([
                    'draw' => $draw,
                    'recordsTotal' => $totalRecords,
                    'recordsFiltered' => $filteredCount,
                    'data' => $paginated,
                ]);
            }

            return response()->json([
                'success' => true,
                'sessions' => $paginated,
                'total' => $filteredCount,
            ]);
        }

        if ($action === 'session' || $action === 'trace' || $action === 'debug_report') {
            $id = $request->query('id', '');
            if (empty($id)) {
                return response()->json(['success' => false, 'state' => 'not_found', 'error' => 'Session ID required'], 400);
            }

            $payload = $this->buildTracePayload($id);
            if (($payload['state'] ?? null) === 'not_found') {
                return response()->json($payload, 404);
            }

            return response()->json($payload);
        }

        if ($action === 'interactions') {
            // Return all child interaction sub-sessions for a given parent PHP session ID
            $parentId = $request->query('parent_id', '');
            if (empty($parentId)) {
                return response()->json(['success' => false, 'error' => 'parent_id is required'], 400);
            }

            $interactions = [];

            // When session isolation is enabled, search per-session monitor.db files
            if ($this->isolationEnabled && $this->sessionManager) {
                $perSessionDb = $this->sessionManager->findMonitorDbForSession($parentId);
                if ($perSessionDb) {
                    $store = new SqliteMonitorStore($perSessionDb);
                    $interactions = $store->getInteractionsByParent($parentId);
                }
            }

            // Fall back to global monitor.db
            if (empty($interactions)) {
                $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
                $store = new SqliteMonitorStore($dbPath);
                $interactions = $store->getInteractionsByParent($parentId);
            }

            // Decode settings snapshot for each interaction
            $interactions = array_map(function (array $row) {
                $row['settings'] = json_decode($row['settings'] ?? '{}', true) ?: [];

                return $row;
            }, $interactions);

            return response()->json([
                'success' => true,
                'parent_id' => $parentId,
                'interactions' => $interactions,
                'total' => count($interactions),
            ]);
        }

        if ($action === 'run') {
            $provider = trim($request->input('provider', 'ollama'));
            $connection = trim($request->input('connection', ''));
            $agentClass = trim($request->input('agent', ''));
            $url = trim($request->input('url', ''));
            $model = trim($request->input('model', ''));
            $prompt = trim($request->input('prompt', ''));
            $useCache = (bool) $request->input('cache', false);
            $useCompact = (bool) $request->input('compact', false);
            $useGuard = (bool) $request->input('guard', false);
            $useQuantum = (bool) $request->input('quantum', false);

            if ($useQuantum && function_exists('config')) {
                config(['harness.quantum_harness.enabled' => true]);
            }

            if (empty($prompt)) {
                return response()->json(['success' => false, 'error' => 'Prompt is required'], 400);
            }

            try {
                $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
                $store = new SqliteMonitorStore($dbPath);
                $sessionId = bin2hex(random_bytes(8));

                $systemPrompt = 'You are a Kali WSL security assistant. Use your tools to run network diagnostics and report findings concisely.';
                $agentDisplayName = 'security-assistant';

                if (! empty($agentClass)) {
                    $discovered = AgentSelector::discover();
                    $matchedAgent = null;
                    foreach ($discovered as $da) {
                        if (strcasecmp($da['name'], $agentClass) === 0 || strcasecmp($da['class'], $agentClass) === 0) {
                            $matchedAgent = $da;
                            break;
                        }
                    }

                    if ($matchedAgent) {
                        $systemPrompt = $matchedAgent['instructions'];
                        if (empty($model)) {
                            $model = $matchedAgent['model'];
                        }
                        if (empty($provider) && ! empty($matchedAgent['provider'])) {
                            $provider = $matchedAgent['provider'];
                        }
                        $agentDisplayName = $matchedAgent['name'];
                    } else {
                        if (class_exists($agentClass)) {
                            try {
                                $instance = new $agentClass;
                                if (method_exists($instance, 'instructions')) {
                                    $systemPrompt = (string) $instance->instructions();
                                    $agentDisplayName = $agentClass;
                                } else {
                                    $agentDisplayName = 'custom';
                                }
                            } catch (\Throwable $e) {
                                $agentDisplayName = 'custom';
                            }
                        } else {
                            $agentDisplayName = 'fallback';
                        }
                    }
                }

                if (empty($model)) {
                    $model = $provider === 'lmstudio' ? 'lmstudio-community/gemma-2b-it-GGUF' : 'hermes-3-llama-3-8b';
                }

                if (empty($url)) {
                    if ($provider === 'lmstudio') {
                        $url = getenv('PHPKAIHARNESS_LMSTUDIO_URL') ?: 'http://localhost:1234';
                    } else {
                        $url = getenv('PHPKAIHARNESS_URL') ?: 'http://localhost:11434';
                    }
                }

                // Instantiate LLM client via the provider factory
                $resolvedConnection = empty($connection)
                    ? (class_exists(Ai::class) && function_exists('app') && app()->bound('config') ? config('ai.default', 'ollama') : 'ollama')
                    : $connection;

                $llmClient = (new LlmClientFactory)->make($provider, $model, [
                    'url' => $url,
                    'connection' => $resolvedConnection,
                ]);

                $registry = new ToolRegistry;
                $registry->attach(new WslCommandTool(
                    name: 'wsl_security_tool',
                    description: 'Runs security diagnostics (ping, dig, whois, curl, nslookup).',
                    allowedBinaries: ['ping', 'dig', 'whois', 'curl', 'nslookup']
                ));

                $agent = new AgentLoop(
                    llmClient: $llmClient,
                    registry: $registry,
                    systemPrompt: $systemPrompt,
                    model: $model,
                    maxIterations: 5,
                );

                if ($useCache) {
                    $agent->setSemanticCache(new SemanticCache(
                        pdo: $store->getPdo(),
                        threshold: 0.88
                    ));
                }

                if ($useCompact) {
                    $agent->setContextCompactor(new ContextCompactor(
                        strategy: 'sliding_window',
                        maxTurns: 4
                    ));
                }

                if ($useGuard) {
                    $agent->setGuardrails(new Guardrails);
                }

                $history = [];
                $store->startSession($sessionId, $prompt, 'web-ui-run ('.$agentDisplayName.')');
                $startTime = microtime(true);
                $response = $agent->run($prompt, $history, $sessionId, $store);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                return response()->json([
                    'success' => true,
                    'sessionId' => $sessionId,
                    'response' => $response,
                    'duration_ms' => $durationMs,
                ]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }
        }

        if ($action === 'agents') {
            try {
                $agents = AgentSelector::discover();

                return response()->json(['success' => true, 'agents' => $agents]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'agents' => [], 'error' => $e->getMessage()]);
            }
        }

        if ($action === 'models') {
            $provider = $request->query('provider', 'ollama');
            $connection = $request->query('connection', '');
            $url = $request->query('url', '');

            // If laravel_ai is selected, resolve its actual driver, url, key from config
            if ($provider === 'laravel_ai' && ! empty($connection) && class_exists(Ai::class) && function_exists('app') && app()->bound('config')) {
                $driver = config("ai.providers.{$connection}.driver", '');
                $url = config("ai.providers.{$connection}.url", '');
                $key = config("ai.providers.{$connection}.key", '');

                if ($driver === 'ollama') {
                    $provider = 'ollama';
                    if (empty($url)) {
                        $url = 'http://localhost:11434';
                    }
                } elseif ($driver === 'openai') {
                    $provider = 'openai_compatible';
                    if (empty($url)) {
                        $url = 'http://localhost:1234/v1'; // fallback to LM Studio default
                    }
                } elseif ($driver === 'gemini') {
                    $provider = 'gemini';
                } else {
                    // General fallback based on connection name
                    if ($connection === 'openrouter') {
                        $provider = 'openrouter';
                    } elseif ($connection === 'lmstudio') {
                        $provider = 'lmstudio';
                        if (empty($url)) {
                            $url = 'http://localhost:1234/v1';
                        }
                    }
                }
            }

            try {
                if ($provider === 'ollama') {
                    $targetUrl = rtrim(empty($url) ? 'http://localhost:11434' : $url, '/').'/api/tags';
                    $ch = curl_init($targetUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ]);
                    $result = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($code === 200) {
                        $data = json_decode($result, true);
                        $models = array_map(fn ($m) => $m['name'], $data['models'] ?? []);

                        return response()->json(['success' => true, 'models' => $models]);
                    } else {
                        return response()->json(['success' => false, 'models' => [], 'error' => "Ollama unreachable at $targetUrl (HTTP $code)"]);
                    }
                } elseif ($provider === 'lmstudio' || $provider === 'openai_compatible') {
                    // LM Studio / OpenAI-compatible endpoint
                    $baseUrl = empty($url) ? 'http://localhost:1234/v1' : $url;
                    if (! str_contains($baseUrl, '/v1') && $provider === 'lmstudio') {
                        $baseUrl = rtrim($baseUrl, '/').'/v1';
                    }
                    $targetUrl = rtrim($baseUrl, '/').'/models';

                    $ch = curl_init($targetUrl);
                    $headers = [];
                    if (isset($key) && ! empty($key)) {
                        $headers[] = 'Authorization: Bearer '.$key;
                    }
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => $headers,
                    ]);
                    $result = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($code === 200) {
                        $data = json_decode($result, true);
                        $models = array_map(fn ($m) => $m['id'], $data['data'] ?? []);

                        return response()->json(['success' => true, 'models' => $models]);
                    } else {
                        return response()->json(['success' => false, 'models' => [], 'error' => "OpenAI compatible server unreachable at $targetUrl (HTTP $code)"]);
                    }
                } elseif ($provider === 'openrouter') {
                    $targetUrl = 'https://openrouter.ai/api/v1/models';
                    $ch = curl_init($targetUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                    ]);
                    $result = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($code === 200) {
                        $data = json_decode($result, true);
                        $models = array_map(fn ($m) => $m['id'], $data['data'] ?? []);

                        return response()->json(['success' => true, 'models' => $models]);
                    } else {
                        return response()->json(['success' => false, 'models' => [], 'error' => "OpenRouter unreachable (HTTP $code)"]);
                    }
                } elseif ($provider === 'gemini') {
                    $models = [
                        'gemini-1.5-flash',
                        'gemini-1.5-pro',
                        'gemini-2.0-flash',
                        'gemini-2.5-flash',
                        'gemini-2.5-pro',
                    ];

                    return response()->json(['success' => true, 'models' => $models]);
                } else {
                    return response()->json(['success' => true, 'models' => ['hermes-3-llama-3-8b', 'gemma-2b']]);
                }
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'models' => [], 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => false, 'error' => "Unknown action: $action"], 400);
    }

    protected function buildTracePayload(string $id): array
    {
        $evaluator = new TraceEvaluator;
        $result = $evaluator->evaluateSession($id);

        if (! $result) {
            return [
                'success' => false,
                'state' => 'not_found',
                'error' => "Session '{$id}' not found",
            ];
        }

        $session = $result['trace']['session'] ?? [];
        $details = $result['trace']['details'] ?? [];
        $state = $result['status'] ?? ($session['status'] ?? 'pending');
        if ($state === 'running' && empty($details) && empty($session['response'] ?? '')) {
            $state = 'pending';
        }

        return [
            'success' => true,
            'state' => $state,
            'session' => array_merge($session, [
                'details' => $details,
                'facts' => $result['trace']['facts'] ?? [],
            ]),
            'session_id' => $result['session_id'],
            'prompt' => $result['prompt'],
            'response' => $result['response'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'total_duration_ms' => $result['total_duration_ms'],
            'evaluation' => $result['evaluation'],
            'summary' => $result['summary'],
            'report' => $result['report'],
            'settings_at_run' => $result['settings_at_run'] ?? [],
            'live_config' => $result['live_config'] ?? [],
            'config_drift' => $result['config_drift'] ?? [],
            'has_config_drift' => $result['has_config_drift'] ?? false,
            'parent_session_id' => $result['parent_session_id'] ?? null,
            'root_session_id' => $result['root_session_id'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'session_type' => $result['session_type'] ?? 'interaction',
            'interaction_index' => $result['interaction_index'] ?? 0,
            'interactions' => $result['interactions'] ?? [],
        ];
    }

    /**
     * List all isolated phpkaiharness sessions.
     *
     * GET /harness/api/sessions-list
     */
    public function listIsolatedSessions(): JsonResponse
    {
        $manager = app(SessionManager::class);
        $sessions = $manager->listSessions();

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
            'total' => count($sessions),
            'total_size' => $manager->getTotalSize(),
            'base_path' => $manager->getBasePath(),
            'isolation_enabled' => $manager->isEnabled(),
        ]);
    }

    /**
     * Get detailed info for a single isolated session.
     *
     * GET /harness/api/sessions-list/{sessionId}
     */
    public function showIsolatedSession(string $sessionId): JsonResponse
    {
        $manager = app(SessionManager::class);
        $info = $manager->getSessionInfo($sessionId);

        if ($info === null) {
            return response()->json(['success' => false, 'error' => 'Session not found'], 404);
        }

        return response()->json(['success' => true, 'session' => $info]);
    }

    /**
     * Delete an isolated session folder.
     *
     * DELETE /harness/api/sessions-list/{sessionId}
     */
    public function deleteIsolatedSession(string $sessionId): JsonResponse
    {
        $manager = app(SessionManager::class);
        $deleted = $manager->deleteSession($sessionId);

        if (! $deleted) {
            return response()->json(['success' => false, 'error' => 'Session not found'], 404);
        }

        return response()->json(['success' => true, 'message' => "Session {$sessionId} deleted"]);
    }

    /**
     * Purge all isolated sessions.
     *
     * POST /harness/api/sessions-purge
     */
    public function purgeAllSessions(): JsonResponse
    {
        $manager = app(SessionManager::class);
        $count = $manager->purgeAll();

        return response()->json(['success' => true, 'message' => "Purged {$count} session(s)"]);
    }

    /**
     * Clean up sessions older than the configured threshold.
     *
     * POST /harness/api/sessions-cleanup
     */
    public function cleanupOldSessions(): JsonResponse
    {
        $manager = app(SessionManager::class);
        $maxAge = (int) config('harness.session_isolation.cleanup_hours', 24);
        $count = $manager->cleanupOld($maxAge);

        return response()->json(['success' => true, 'message' => "Cleaned up {$count} session(s) older than {$maxAge}h"]);
    }
}
