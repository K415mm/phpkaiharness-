<?php

namespace Phpkaiharness\Monitor;

use PDO;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;

/**
 * Standalone SQLite-backed analytics store.
 *
 * Stores agent session data, LLM call traces, and tool execution logs
 * in a local SQLite file — no framework or database server required.
 *
 * Default DB path: ~/.phpkaiharness/monitor.db
 * Override via PHPKAIHARNESS_DB environment variable.
 */
class SqliteMonitorStore implements AnalyticsCollectorInterface
{
    private PDO $pdo;

    private string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? self::defaultDbPath();
        // Normalize Windows drive paths to WSL mount paths when running on Linux
        if (DIRECTORY_SEPARATOR === '/' && is_string($this->dbPath) && preg_match('/^[a-zA-Z]:[\\\\\/]/', $this->dbPath)) {
            $drive = strtolower($this->dbPath[0]);
            $this->dbPath = '/mnt/'.$drive.str_replace(['\\', '/'], '/', substr($this->dbPath, 2));
        }
        $this->pdo = $this->connect();
        $this->initSchema();
    }

    /**
     * Resolve the default database path from the user home directory.
     */
    public static function defaultDbPath(): string
    {
        if (function_exists('storage_path') && function_exists('app') && method_exists(app(), 'storagePath')) {
            return storage_path('app/phpkaiharness/monitor.db');
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return $home.DIRECTORY_SEPARATOR.'.phpkaiharness'.DIRECTORY_SEPARATOR.'monitor.db';
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    private function connect(): PDO
    {
        $dir = dirname($this->dbPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }


        if (! extension_loaded('pdo_sqlite') && ! extension_loaded('sqlite3')) {
            throw new \RuntimeException(
                "SQLite extension not available.\nOn Kali/Debian WSL: sudo apt install php-sqlite3\n"
            );
        }

        $pdo = new PDO('sqlite:'.$this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Use DELETE journal mode (not WAL) for DrvFs compatibility.
        // WAL requires POSIX shared memory (-shm file) which fails on
        // WSL DrvFs mounts, causing silent write failures from Horizon workers.
        $pdo->exec('PRAGMA journal_mode=DELETE;');
        // Wait up to 5 seconds when database is locked by another process
        $pdo->exec('PRAGMA busy_timeout=5000;');
        $pdo->exec('PRAGMA foreign_keys=ON;');

        return $pdo;
    }

    private function initSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS harness_sessions (
                id TEXT PRIMARY KEY,
                prompt TEXT NOT NULL,
                response TEXT DEFAULT '',
                method TEXT NOT NULL DEFAULT 'unknown',
                iterations INTEGER DEFAULT 0,
                total_duration_ms INTEGER DEFAULT 0,
                settings TEXT DEFAULT '{}',
                parent_session_id TEXT NULL,
                root_session_id TEXT NULL,
                request_id TEXT NULL,
                session_type TEXT DEFAULT 'interaction',
                status TEXT DEFAULT 'running',
                error TEXT NULL,
                interaction_index INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS harness_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                payload TEXT DEFAULT '{}',
                response TEXT DEFAULT '',
                duration_ms INTEGER DEFAULT 0,
                tokens_prompt INTEGER DEFAULT 0,
                tokens_completion INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (session_id) REFERENCES harness_sessions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS harness_facts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                fact TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (session_id) REFERENCES harness_sessions(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_details_session ON harness_details(session_id);
            CREATE INDEX IF NOT EXISTS idx_sessions_created ON harness_sessions(created_at);
            CREATE INDEX IF NOT EXISTS idx_facts_session ON harness_facts(session_id);
        SQL);

        // Safe migrations for columns added after initial schema.
        // Run these BEFORE creating any index that references the new columns.
        $migrations = [
            "ALTER TABLE harness_sessions ADD COLUMN settings TEXT DEFAULT '{}';",
            'ALTER TABLE harness_sessions ADD COLUMN parent_session_id TEXT NULL;',
            'ALTER TABLE harness_sessions ADD COLUMN root_session_id TEXT NULL;',
            'ALTER TABLE harness_sessions ADD COLUMN request_id TEXT NULL;',
            "ALTER TABLE harness_sessions ADD COLUMN session_type TEXT DEFAULT 'interaction';",
            "ALTER TABLE harness_sessions ADD COLUMN status TEXT DEFAULT 'running';",
            'ALTER TABLE harness_sessions ADD COLUMN error TEXT NULL;',
            'ALTER TABLE harness_sessions ADD COLUMN interaction_index INTEGER DEFAULT 0;',
            // harness_facts schema upgrade — quality and provenance columns
            'ALTER TABLE harness_facts ADD COLUMN confidence REAL DEFAULT 1.0;',
            "ALTER TABLE harness_facts ADD COLUMN category TEXT DEFAULT 'general';",
            'ALTER TABLE harness_facts ADD COLUMN entity TEXT NULL;',
            "ALTER TABLE harness_facts ADD COLUMN source_type TEXT DEFAULT 'agent';",
            'ALTER TABLE harness_facts ADD COLUMN source_id TEXT NULL;',
            'ALTER TABLE harness_facts ADD COLUMN superseded_by INTEGER NULL;',
            'ALTER TABLE harness_facts ADD COLUMN superseded_at TEXT NULL;',
        ];
        foreach ($migrations as $ddl) {
            try {
                $this->pdo->exec($ddl);
            } catch (\Throwable $e) {
                // Column already exists — safe to ignore
            }
        }

        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_parent ON harness_sessions(parent_session_id);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_root ON harness_sessions(root_session_id);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_request ON harness_sessions(request_id);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_status ON harness_sessions(status);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_facts_entity ON harness_facts(entity);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_facts_category ON harness_facts(category);');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_facts_superseded ON harness_facts(superseded_by);');
        } catch (\Throwable $e) {
        }
    }

    public function startSession(
        string $sessionId,
        string $prompt,
        string $method,
        ?string $parentSessionId = null,
        int $interactionIndex = 0,
        ?string $rootSessionId = null,
        ?string $requestId = null,
        string $sessionType = 'interaction'
    ): void {
        $settings = [];
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            $settings = config('harness') ?: [];
        }
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($settingsJson === false) {
            $settingsJson = '{}';
        }

        if ($parentSessionId === null && function_exists('app') && app()->bound('harness.parent_session_id')) {
            $parentSessionId = app('harness.parent_session_id');
        }
        if ($rootSessionId === null && function_exists('app') && app()->bound('harness.root_session_id')) {
            $rootSessionId = app('harness.root_session_id');
        }
        $rootSessionId ??= $parentSessionId ?? $sessionId;
        $requestId ??= $sessionId;

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_sessions
             (id, prompt, method, settings, parent_session_id, root_session_id, request_id, session_type, status, interaction_index, created_at, updated_at)
             VALUES (:id, :prompt, :method, :settings, :parent, :root, :request, :session_type, 'running', :idx, datetime('now'), datetime('now'))
             ON CONFLICT(id) DO UPDATE SET
               prompt = :prompt, method = :method, settings = :settings,
               parent_session_id = COALESCE(:parent, parent_session_id),
               root_session_id = COALESCE(:root, root_session_id),
               request_id = COALESCE(:request, request_id),
               session_type = :session_type, status = 'running', error = NULL, interaction_index = :idx,
               updated_at = datetime('now')"
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':prompt' => $prompt,
            ':method' => $method,
            ':settings' => $settingsJson,
            ':parent' => $parentSessionId,
            ':root' => $rootSessionId,
            ':request' => $requestId,
            ':session_type' => $sessionType,
            ':idx' => $interactionIndex,
        ]);
    }

    /**
     * Return all child interaction rows for a given parent PHP session, ordered by index.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInteractionsByParent(string $parentSessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM harness_sessions WHERE parent_session_id = ? OR root_session_id = ? ORDER BY interaction_index ASC, created_at ASC'
        );
        $stmt->execute([$parentSessionId, $parentSessionId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findSession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM harness_sessions WHERE id = ? OR request_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$sessionId, $sessionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function endSession(string $sessionId, string $response, int $totalDurationMs, int $iterations): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE harness_sessions SET response = :response, total_duration_ms = :duration,
             iterations = :iterations, status = 'completed', error = NULL, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([
            ':response' => $response,
            ':duration' => $totalDurationMs,
            ':iterations' => $iterations,
            ':id' => $sessionId,
        ]);
    }

    public function failSession(string $sessionId, string $error, int $totalDurationMs = 0, int $iterations = 0): void
    {
        $this->ensureSessionExists($sessionId);
        $stmt = $this->pdo->prepare(
            "UPDATE harness_sessions SET status = 'failed', error = :error, response = :response,
             total_duration_ms = :duration, iterations = :iterations, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([
            ':error' => $error,
            ':response' => $error,
            ':duration' => $totalDurationMs,
            ':iterations' => $iterations,
            ':id' => $sessionId,
        ]);
    }

    private function ensureSessionExists(string $sessionId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO harness_sessions (id, prompt, method, settings, root_session_id, request_id, session_type, status, created_at, updated_at)
             VALUES (:sid, '', 'auto', '{}', :sid, :sid, 'interaction', 'running', datetime('now'), datetime('now'))"
        );
        $stmt->execute([':sid' => $sessionId]);
    }

    public function recordLlmCall(
        string $sessionId,
        string $model,
        array $payload,
        array $response,
        int $durationMs,
        array $usage
    ): void {
        $this->ensureSessionExists($sessionId);

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_details
             (session_id, type, name, payload, response, duration_ms, tokens_prompt, tokens_completion, created_at)
             VALUES (:sid, 'llm_call', :name, :payload, :response, :duration, :pt, :ct, datetime('now'))"
        );
        $stmt->execute([
            ':sid' => $sessionId,
            ':name' => $model,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            ':response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            ':duration' => $durationMs,
            ':pt' => $usage['prompt_tokens'] ?? 0,
            ':ct' => $usage['completion_tokens'] ?? 0,
        ]);
    }

    public function recordToolCall(
        string $sessionId,
        string $toolName,
        array $arguments,
        string $result,
        int $durationMs
    ): void {
        $this->ensureSessionExists($sessionId);

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_details
             (session_id, type, name, payload, response, duration_ms, created_at)
             VALUES (:sid, 'tool_call', :name, :payload, :response, :duration, datetime('now'))"
        );
        $resultJson = $result;
        if (! empty($result) && ! $this->isValidJson($result)) {
            $encoded = json_encode(['message' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $resultJson = $encoded !== false ? $encoded : '{}';
        }

        $stmt->execute([
            ':sid' => $sessionId,
            ':name' => $toolName,
            ':payload' => json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            ':response' => $resultJson,
            ':duration' => $durationMs,
        ]);
    }

    public function recordEvent(
        string $sessionId,
        string $type,
        string $name,
        array $payload,
        string $response,
        int $durationMs = 0
    ): void {
        $this->ensureSessionExists($sessionId);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payloadJson === false) {
            $payloadJson = '{}';
        }

        $responseJson = $response;
        if (! empty($response) && ! $this->isValidJson($response)) {
            $encoded = json_encode(['message' => $response], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $responseJson = $encoded !== false ? $encoded : '{}';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_details
             (session_id, type, name, payload, response, duration_ms, created_at)
             VALUES (:sid, :type, :name, :payload, :response, :duration, datetime('now'))"
        );
        $stmt->execute([
            ':sid' => $sessionId,
            ':type' => $type,
            ':name' => $name,
            ':payload' => $payloadJson,
            ':response' => $responseJson,
            ':duration' => $durationMs,
        ]);
    }

    /**
     * Check if a string is valid JSON.
     */
    private function isValidJson(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Record a persistent fact/relationship to the cognitive graph memory layer.
     */
    public function recordFact(string $sessionId, string $fact): void
    {
        $this->ensureSessionExists($sessionId);

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_facts (session_id, fact, created_at)
             VALUES (:sid, :fact, datetime('now'))"
        );
        $stmt->execute([':sid' => $sessionId, ':fact' => $fact]);
    }

    /**
     * Record a fact with quality and provenance metadata.
     *
     * @param  array{confidence?: float, category?: string, entity?: ?string, source_type?: string, source_id?: ?string}  $metadata
     */
    public function recordFactWithMetadata(string $sessionId, string $fact, array $metadata = []): int
    {
        $this->ensureSessionExists($sessionId);

        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_facts
             (session_id, fact, confidence, category, entity, source_type, source_id, created_at)
             VALUES (:sid, :fact, :confidence, :category, :entity, :source_type, :source_id, datetime('now'))"
        );
        $stmt->execute([
            ':sid' => $sessionId,
            ':fact' => $fact,
            ':confidence' => $metadata['confidence'] ?? 1.0,
            ':category' => $metadata['category'] ?? 'general',
            ':entity' => $metadata['entity'] ?? null,
            ':source_type' => $metadata['source_type'] ?? 'agent',
            ':source_id' => $metadata['source_id'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Find facts similar to the given text for deduplication.
     * Uses simple LIKE matching on the fact text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSimilarFacts(string $factText, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fact, confidence, category, entity, superseded_by
             FROM harness_facts
             WHERE superseded_by IS NULL
               AND (fact LIKE :pattern OR :fact LIKE "%" || fact || "%")
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':pattern', '%'.$factText.'%');
        $stmt->bindValue(':fact', $factText);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Mark a fact as superseded by a newer fact.
     */
    public function supersedeFact(int $oldFactId, int $newFactId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE harness_facts
             SET superseded_by = :new_id, superseded_at = datetime('now')
             WHERE id = :old_id AND superseded_by IS NULL"
        );
        $stmt->execute([':new_id' => $newFactId, ':old_id' => $oldFactId]);
    }
}
