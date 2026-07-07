<?php

namespace Phpkaiharness\Monitor;

use PDO;

/**
 * Read-only analytics report helper.
 *
 * Opens the SQLite database in read mode and exposes aggregated
 * stats, session lists, and session detail queries used by both
 * the CLI commands and the web UI dashboard.
 */
class MonitorReport
{
    private PDO $pdo;

    /**
     * @param  string|SqliteMonitorStore  $source  DB file path or an existing store instance.
     */
    public function __construct(string|SqliteMonitorStore $source)
    {
        if ($source instanceof SqliteMonitorStore) {
            $this->pdo = $source->getPdo();
        } else {
            $store = new SqliteMonitorStore($source);
            $this->pdo = $store->getPdo();
        }
    }

    /**
     * Return the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Return aggregated analytics statistics.
     *
     * @return array{
     *   total_sessions: int,
     *   total_prompt_tokens: int,
     *   total_completion_tokens: int,
     *   avg_duration_ms: int,
     *   fast_path_saves: int,
     *   total_tool_calls: int,
     *   total_llm_calls: int,
     *   methods: array<array{method: string, count: int}>
     * }
     */
    public function getStats(): array
    {
        $totalSessions = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM harness_sessions'
        )->fetchColumn();

        $tokenRow = $this->pdo->query(
            "SELECT COALESCE(SUM(tokens_prompt),0) AS pt, COALESCE(SUM(tokens_completion),0) AS ct
             FROM harness_details WHERE type = 'llm_call'"
        )->fetch();

        $avgDuration = (int) ($this->pdo->query(
            'SELECT COALESCE(AVG(total_duration_ms),0) FROM harness_sessions WHERE total_duration_ms > 0'
        )->fetchColumn() ?? 0);

        $fastPathSaves = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM harness_sessions WHERE method = 'fast-path-keyword'"
        )->fetchColumn();

        $toolCalls = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM harness_details WHERE type = 'tool_call'"
        )->fetchColumn();

        $llmCalls = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM harness_details WHERE type = 'llm_call'"
        )->fetchColumn();

        $methods = $this->pdo->query(
            'SELECT method, COUNT(*) as count FROM harness_sessions GROUP BY method ORDER BY count DESC'
        )->fetchAll();

        return [
            'total_sessions' => $totalSessions,
            'total_prompt_tokens' => (int) ($tokenRow['pt'] ?? 0),
            'total_completion_tokens' => (int) ($tokenRow['ct'] ?? 0),
            'avg_duration_ms' => $avgDuration,
            'fast_path_saves' => $fastPathSaves,
            'total_tool_calls' => $toolCalls,
            'total_llm_calls' => $llmCalls,
            'methods' => $methods,
        ];
    }

    /**
     * Return paginated list of recent sessions with token aggregates.
     *
     * @return array<array<string,mixed>>
     */
    public function getSessions(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*,
                    COALESCE(SUM(d.tokens_prompt),0)     AS tokens_prompt,
                    COALESCE(SUM(d.tokens_completion),0) AS tokens_completion,
                    COUNT(CASE WHEN d.type=\'llm_call\'  THEN 1 END) AS llm_calls,
                    COUNT(CASE WHEN d.type=\'tool_call\' THEN 1 END) AS tool_calls
             FROM harness_sessions s
             LEFT JOIN harness_details d ON d.session_id = s.id
             GROUP BY s.id
             ORDER BY s.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Return paginated, sorted, and filtered list of recent sessions with token aggregates.
     *
     * @return array<array<string,mixed>>
     */
    public function getSessionsFiltered(int $limit, int $offset, ?string $search = null, ?string $sortColumn = null, string $sortDir = 'desc'): array
    {
        $query = 'SELECT s.*,
                         COALESCE(SUM(d.tokens_prompt),0)     AS tokens_prompt,
                         COALESCE(SUM(d.tokens_completion),0) AS tokens_completion,
                         COUNT(CASE WHEN d.type=\'llm_call\'  THEN 1 END) AS llm_calls,
                         COUNT(CASE WHEN d.type=\'tool_call\' THEN 1 END) AS tool_calls
                  FROM harness_sessions s
                  LEFT JOIN harness_details d ON d.session_id = s.id';

        $params = [];
        if (! empty($search)) {
            $query .= ' WHERE s.id LIKE :search OR s.method LIKE :search OR s.prompt LIKE :search';
            $params[':search'] = '%'.$search.'%';
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
        $dir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        $query .= " ORDER BY {$sort} {$dir}";

        $query .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return total count of filtered sessions.
     */
    public function getSessionCountFiltered(?string $search = null): int
    {
        if (empty($search)) {
            return $this->getSessionCount();
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM harness_sessions WHERE id LIKE :search OR method LIKE :search OR prompt LIKE :search'
        );
        $stmt->execute([':search' => '%'.$search.'%']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Return a single session with all associated detail steps.
     *
     * @return array<string,mixed>|null
     */
    public function getSession(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM harness_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch();

        if (! $session) {
            return null;
        }

        $detailStmt = $this->pdo->prepare(
            'SELECT * FROM harness_details WHERE session_id = :id ORDER BY created_at ASC, id ASC'
        );
        $detailStmt->execute([':id' => $id]);
        $session['details'] = $detailStmt->fetchAll();

        return $session;
    }

    /**
     * Return total session count.
     */
    public function getSessionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM harness_sessions')->fetchColumn();
    }

    /**
     * Return chart-friendly daily session stats for the last N days.
     *
     * @return array<array{date: string, sessions: int, tokens: int}>
     */
    public function getDailyStats(int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                DATE(s.created_at) AS date,
                COUNT(DISTINCT s.id) AS sessions,
                COALESCE(SUM(d.tokens_prompt + d.tokens_completion), 0) AS tokens
             FROM harness_sessions s
             LEFT JOIN harness_details d ON d.session_id = s.id AND d.type = 'llm_call'
             WHERE s.created_at >= datetime('now', :offset)
             GROUP BY DATE(s.created_at)
             ORDER BY date ASC"
        );
        $stmt->execute([':offset' => "-{$days} days"]);

        return $stmt->fetchAll();
    }
}
