<?php

namespace Phpkaiharness\Session;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Optimize\QuantumInferenceEngine;

/**
 * Manages per-session isolation for phpkaiharness.
 *
 * Maps each Laravel session ID to a dedicated folder with its own
 * SQLite monitor database and quantum memory database.
 *
 * Folder structure:
 *   storage/app/phpkaiharness/sessions/{sessionId}/
 *     ├── monitor.db
 *     ├── agent_memory.sqlite
 *     └── context.json   (optional conversation context cache)
 */
class SessionManager
{
    private string $baseSessionsPath;

    public function __construct()
    {
        $this->baseSessionsPath = $this->resolveBasePath();
    }

    /**
     * Resolve the base sessions directory from config or default.
     */
    private function resolveBasePath(): string
    {
        $configured = config('harness.session_isolation.base_path');

        if ($configured) {
            return $configured;
        }

        if (function_exists('storage_path')) {
            return storage_path('app/phpkaiharness/sessions');
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return $home.DIRECTORY_SEPARATOR.'.phpkaiharness'.DIRECTORY_SEPARATOR.'sessions';
    }

    /**
     * Get the base sessions path.
     */
    public function getBasePath(): string
    {
        return $this->baseSessionsPath;
    }

    /**
     * Check if session isolation is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('harness.session_isolation.enabled', true);
    }

    /**
     * Get the folder path for a specific session.
     */
    public function getSessionPath(string $sessionId): string
    {
        return $this->baseSessionsPath.DIRECTORY_SEPARATOR.$sessionId;
    }

    /**
     * Get the monitor DB path for a session.
     */
    public function getMonitorDbPath(string $sessionId): string
    {
        return $this->getSessionPath($sessionId).DIRECTORY_SEPARATOR.'monitor.db';
    }

    /**
     * Get the quantum memory DB path for a session.
     */
    public function getQuantumDbPath(string $sessionId): string
    {
        return $this->getSessionPath($sessionId).DIRECTORY_SEPARATOR.'agent_memory.sqlite';
    }

    /**
     * Get the context cache path for a session.
     */
    public function getContextPath(string $sessionId): string
    {
        return $this->getSessionPath($sessionId).DIRECTORY_SEPARATOR.'context.json';
    }

    /**
     * Ensure the session folder exists and initialize all session artifacts:
     *   - monitor.db           (SQLite trace store, schema bootstrapped)
     *   - agent_memory.sqlite  (SQLite quantum memory store, schema bootstrapped)
     *   - context.json         (conversation context cache, empty array)
     */
    public function ensureSession(string $sessionId): void
    {
        $path = $this->getSessionPath($sessionId);

        if (! is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        // 1. Monitor DB — initialize schema via SqliteMonitorStore
        $monitorDb = $this->getMonitorDbPath($sessionId);
        if (! file_exists($monitorDb) || (int) @filesize($monitorDb) === 0) {
            @mkdir(dirname($monitorDb), 0777, true);
            @file_put_contents($monitorDb, '');
            new SqliteMonitorStore($monitorDb);
        }

        // 2. Quantum memory DB — bootstrap schema via QuantumInferenceEngine
        $quantumDb = $this->getQuantumDbPath($sessionId);
        if (! File::exists($quantumDb) || (int) File::size($quantumDb) === 0) {
            try {
                (new QuantumInferenceEngine($quantumDb))->getPdo();
            } catch (\Throwable $e) {
                // QuantumInferenceEngine unavailable — create a minimal valid SQLite file
                try {
                    $pdo = new \PDO('sqlite:'.$quantumDb);
                    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT, key TEXT, value TEXT, created_at TEXT DEFAULT (datetime(\'now\')));');
                    $pdo = null;
                } catch (\Throwable) {
                    // Non-fatal
                }
            }
        }

        // 3. Context JSON — empty conversation context cache
        $contextPath = $this->getContextPath($sessionId);
        if (! File::exists($contextPath)) {
            File::put($contextPath, '[]');
        }
    }

    /**
     * Activate session-scoped monitor and quantum paths for the current request.
     */
    public function activateSession(string $sessionId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->ensureSession($sessionId);

        $monitorDbPath = $this->getMonitorDbPath($sessionId);
        $quantumDbPath = $this->getQuantumDbPath($sessionId);

        $globalQuantumDb = function_exists('storage_path')
            ? storage_path('app/phpkaiharness/agent_memory.sqlite')
            : $quantumDbPath;

        config([
            'harness.cache.db_path' => $monitorDbPath,
            'harness.quantum_harness.db_path' => $globalQuantumDb,
            'database.connections.agent_memory_sqlite.database' => $globalQuantumDb,
        ]);

        if (function_exists('app')) {
            app()->forgetInstance(SqliteMonitorStore::class);
            app()->forgetInstance(QuantumInferenceEngine::class);
        }

        if (function_exists('app') && app()->bound('db')) {
            DB::purge('agent_memory_sqlite');
        }
    }

    /**
     * Resolve the effective monitor DB path for a session.
     *
     * If isolation is enabled, returns the per-session path.
     * Otherwise falls back to the global default.
     */
    public function resolveMonitorDbPath(string $sessionId): string
    {
        if (! $this->isEnabled()) {
            return config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath();
        }

        $this->ensureSession($sessionId);

        return $this->getMonitorDbPath($sessionId);
    }

    /**
     * Resolve the effective quantum DB path for a session.
     */
    public function resolveQuantumDbPath(string $sessionId): string
    {
        if (! $this->isEnabled()) {
            return config('harness.quantum_harness.db_path') ?: (
                function_exists('storage_path')
                    ? storage_path('app/phpkaiharness/agent_memory.sqlite')
                    : ''
            );
        }

        $this->ensureSession($sessionId);

        return $this->getQuantumDbPath($sessionId);
    }

    /**
     * Get a SqliteMonitorStore instance for the given session.
     */
    public function getMonitorStore(string $sessionId): SqliteMonitorStore
    {
        return new SqliteMonitorStore($this->resolveMonitorDbPath($sessionId));
    }

    /**
     * List all session folders with metadata.
     *
     * @return array<int, array{
     *   id: string,
     *   path: string,
     *   size_bytes: int,
     *   monitor_db: bool,
     *   quantum_db: bool,
     *   context: bool,
     *   created_at: string|null,
     *   last_modified: string|null
     * }>
     */
    public function listSessions(): array
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return [];
        }

        $dirs = File::directories($this->baseSessionsPath);
        $sessions = [];

        foreach ($dirs as $dir) {
            $name = basename($dir);
            $size = $this->folderSize($dir);
            $mtime = File::lastModified($dir);

            $sessions[] = [
                'id' => $name,
                'path' => $dir,
                'size_bytes' => $size,
                'size_human' => $this->formatBytes($size),
                'monitor_db' => File::exists($dir.DIRECTORY_SEPARATOR.'monitor.db'),
                'quantum_db' => File::exists($dir.DIRECTORY_SEPARATOR.'agent_memory.sqlite') || (function_exists('storage_path') && File::exists(storage_path('app/phpkaiharness/agent_memory.sqlite'))),
                'context' => File::exists($dir.DIRECTORY_SEPARATOR.'context.json'),
                'created_at' => date('Y-m-d H:i:s', $mtime),
                'last_modified' => date('Y-m-d H:i:s', $mtime),
            ];
        }

        // Sort by last modified descending
        usort($sessions, fn ($a, $b) => strcmp($b['last_modified'], $a['last_modified']));

        return $sessions;
    }

    /**
     * Get detailed info for a single session.
     */
    public function getSessionInfo(string $sessionId): ?array
    {
        $path = $this->getSessionPath($sessionId);

        if (! File::isDirectory($path)) {
            return null;
        }

        $size = $this->folderSize($path);
        $mtime = File::lastModified($path);

        // Try to get session stats from the monitor DB
        $stats = ['sessions' => 0, 'details' => 0, 'facts' => 0];
        $monitorDb = $this->getMonitorDbPath($sessionId);
        if (File::exists($monitorDb) && filesize($monitorDb) > 0) {
            try {
                $pdo = new \PDO('sqlite:'.$monitorDb);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $stats['sessions'] = (int) $pdo->query('SELECT COUNT(*) FROM harness_sessions')->fetchColumn();
                $stats['details'] = (int) $pdo->query('SELECT COUNT(*) FROM harness_details')->fetchColumn();
                $stats['facts'] = (int) $pdo->query('SELECT COUNT(*) FROM harness_facts')->fetchColumn();
                $pdo = null;
            } catch (\Throwable $e) {
                // Ignore DB errors
            }
        }

        return [
            'id' => $sessionId,
            'path' => $path,
            'size_bytes' => $size,
            'size_human' => $this->formatBytes($size),
            'monitor_db' => File::exists($monitorDb),
            'quantum_db' => File::exists($this->getQuantumDbPath($sessionId)),
            'context' => File::exists($this->getContextPath($sessionId)),
            'created_at' => date('Y-m-d H:i:s', $mtime),
            'last_modified' => date('Y-m-d H:i:s', $mtime),
            'stats' => $stats,
        ];
    }

    /**
     * Delete a session folder and all its contents.
     */
    public function deleteSession(string $sessionId): bool
    {
        $path = $this->getSessionPath($sessionId);

        if (! File::isDirectory($path)) {
            return false;
        }

        File::deleteDirectory($path);

        return true;
    }

    /**
     * Purge all session folders (dangerous!).
     */
    public function purgeAll(): int
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return 0;
        }

        $dirs = File::directories($this->baseSessionsPath);
        $count = 0;

        foreach ($dirs as $dir) {
            File::deleteDirectory($dir);
            $count++;
        }

        return $count;
    }

    /**
     * Clean up sessions older than the given number of hours.
     */
    public function cleanupOld(int $maxAgeHours = 24): int
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return 0;
        }

        $dirs = File::directories($this->baseSessionsPath);
        $cutoff = time() - ($maxAgeHours * 3600);
        $count = 0;

        foreach ($dirs as $dir) {
            if (File::lastModified($dir) < $cutoff) {
                File::deleteDirectory($dir);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get total size of all session folders.
     */
    public function getTotalSize(): int
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return 0;
        }

        return $this->folderSize($this->baseSessionsPath);
    }

    /**
     * Get total number of session folders.
     */
    public function getSessionCount(): int
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return 0;
        }

        return count(File::directories($this->baseSessionsPath));
    }

    /**
     * Find which per-session monitor.db contains the given harness/session/request ID.
     *
     * Scans all session folders' monitor.db files for a matching harness_sessions row.
     * Returns the absolute path to the monitor.db, or null if not found.
     */
    public function findMonitorDbForSession(string $harnessSessionId): ?string
    {
        return $this->resolveTraceDbPath($harnessSessionId);
    }

    public function resolveTraceDbPath(string $id): ?string
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return null;
        }

        $directPath = $this->getMonitorDbPath($id);
        if (File::exists($directPath) && (int) File::size($directPath) > 0) {
            return $directPath;
        }

        foreach (File::directories($this->baseSessionsPath) as $dir) {
            $monitorDb = $dir.DIRECTORY_SEPARATOR.'monitor.db';
            if (! File::exists($monitorDb) || (int) File::size($monitorDb) === 0) {
                continue;
            }

            try {
                $pdo = new \PDO('sqlite:'.$monitorDb);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM harness_sessions
                     WHERE id = ? OR parent_session_id = ? OR root_session_id = ? OR request_id = ?'
                );
                $stmt->execute([$id, $id, $id, $id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $pdo = null;

                    return $monitorDb;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    public function resolveTraceStore(string $id): ?SqliteMonitorStore
    {
        $dbPath = $this->resolveTraceDbPath($id);

        return $dbPath ? new SqliteMonitorStore($dbPath) : null;
    }

    /**
     * Collect all sessions from all per-session monitor.db files.
     *
     * Returns an array of session rows (as associative arrays) merged from
     * every isolated session folder, sorted by created_at descending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectAllSessions(int $limit = 50): array
    {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return [];
        }

        $allSessions = [];

        foreach (File::directories($this->baseSessionsPath) as $dir) {
            $monitorDb = $dir.DIRECTORY_SEPARATOR.'monitor.db';
            if (! File::exists($monitorDb) || (int) File::size($monitorDb) === 0) {
                continue;
            }

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

                $phpSessionId = basename($dir);
                foreach ($rows as $row) {
                    $row['php_session_id'] = $phpSessionId;
                    $allSessions[] = $row;
                }
            } catch (\Throwable $e) {
                // Skip corrupt or incomplete databases
            }
        }

        // Sort by created_at descending
        usort($allSessions, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        return array_slice($allSessions, 0, $limit);
    }

    /**
     * Collect aggregated main (PHP) sessions from all isolated folders with filters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectMainSessions(
        int $limit = 50,
        ?string $method = null,
        ?string $status = null,
        ?int $minLlm = null,
        ?int $minTool = null
    ): array {
        if (! File::isDirectory($this->baseSessionsPath)) {
            return [];
        }

        $mainSessions = [];

        foreach (File::directories($this->baseSessionsPath) as $dir) {
            $phpSessionId = basename($dir);
            $monitorDb = $dir.DIRECTORY_SEPARATOR.'monitor.db';

            $stats = [
                'php_session_id' => $phpSessionId,
                'sub_session_count' => 0,
                'last_active' => null,
                'total_duration' => 0,
                'total_prompt_tokens' => 0,
                'total_completion_tokens' => 0,
                'llm_calls' => 0,
                'tool_calls' => 0,
            ];

            if (File::exists($monitorDb) && (int) File::size($monitorDb) > 0) {
                try {
                    $pdo = new \PDO('sqlite:'.$monitorDb);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                    // Fetch all sub-sessions with their details aggregates from this monitor db
                    $query = 'SELECT s.id, s.total_duration_ms, s.created_at, s.method, s.status,
                                     COALESCE(SUM(d.tokens_prompt), 0) as prompt,
                                     COALESCE(SUM(d.tokens_completion), 0) as completion,
                                     COUNT(CASE WHEN d.type = \'llm_call\' THEN 1 END) as llms,
                                     COUNT(CASE WHEN d.type = \'tool_call\' THEN 1 END) as tools
                              FROM harness_sessions s
                              LEFT JOIN harness_details d ON d.session_id = s.id
                              GROUP BY s.id';

                    $rows = $pdo->query($query)->fetchAll(\PDO::FETCH_ASSOC);

                    // Filter the rows in PHP
                    $matchingRows = array_filter($rows, function ($row) use ($method, $status, $minLlm, $minTool) {
                        if ($method && ($row['method'] ?? '') !== $method) {
                            return false;
                        }
                        if ($status && ($row['status'] ?? '') !== $status) {
                            return false;
                        }
                        if ($minLlm !== null && (int) $row['llms'] < $minLlm) {
                            return false;
                        }
                        if ($minTool !== null && (int) $row['tools'] < $minTool) {
                            return false;
                        }

                        return true;
                    });

                    if (count($matchingRows) > 0) {
                        $stats['sub_session_count'] = count($matchingRows);
                        $stats['last_active'] = max(array_column($matchingRows, 'created_at'));
                        $stats['total_duration'] = array_sum(array_column($matchingRows, 'total_duration_ms'));
                        $stats['total_prompt_tokens'] = array_sum(array_column($matchingRows, 'prompt'));
                        $stats['total_completion_tokens'] = array_sum(array_column($matchingRows, 'completion'));
                        $stats['llm_calls'] = array_sum(array_column($matchingRows, 'llms'));
                        $stats['tool_calls'] = array_sum(array_column($matchingRows, 'tools'));
                    } else {
                        // Skip this PHP session entirely if no sub-sessions match the filter!
                        $pdo = null;

                        continue;
                    }
                    $pdo = null;
                } catch (\Throwable $e) {
                    $stats['last_active'] = date('Y-m-d H:i:s', File::lastModified($monitorDb));
                }
            } else {
                // If filters are active, skip empty sessions since they don't match any criteria
                if ($method || $status || $minLlm !== null || $minTool !== null) {
                    continue;
                }
                $stats['last_active'] = date('Y-m-d H:i:s', File::lastModified($dir));
            }

            $mainSessions[] = $stats;
        }

        // Sort by last_active descending
        usort($mainSessions, fn ($a, $b) => strcmp($b['last_active'] ?? '', $a['last_active'] ?? ''));

        return array_slice($mainSessions, 0, $limit);
    }

    /**
     * Recursively calculate folder size.
     */
    private function folderSize(string $path): int
    {
        if (! File::isDirectory($path)) {
            return File::exists($path) ? (int) File::size($path) : 0;
        }

        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += (int) $file->getSize();
        }

        return $size;
    }

    /**
     * Format bytes into human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $power), 2).' '.$units[(int) $power];
    }
}
