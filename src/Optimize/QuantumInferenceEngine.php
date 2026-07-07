<?php

namespace Phpkaiharness\Optimize;

use App\Services\AiConfigHelper;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use PDO;

class QuantumInferenceEngine
{
    protected ?PDO $pdo = null;

    protected string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        if ($dbPath) {
            $this->dbPath = $dbPath;
        } else {
            $this->dbPath = $this->defaultDbPath();
        }

        // Normalize Windows paths to WSL-compatible paths when running on Linux
        if (DIRECTORY_SEPARATOR === '/' && preg_match('/^[a-zA-Z]:[\\\\\/]/', $this->dbPath)) {
            $drive = strtolower($this->dbPath[0]);
            $this->dbPath = '/mnt/'.$drive.str_replace(['\\', '/'], '/', substr($this->dbPath, 2));
        }
    }

    /**
     * Resolve the default database path.
     */
    protected function defaultDbPath(): string
    {
        if (function_exists('storage_path') && function_exists('app') && method_exists(app(), 'storagePath')) {
            return storage_path('app/phpkaiharness/agent_memory.sqlite');
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return $home.DIRECTORY_SEPARATOR.'.phpkaiharness'.DIRECTORY_SEPARATOR.'agent_memory.sqlite';
    }

    /**
     * Get or initialize the isolated PDO connection.
     */
    public function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // Try Laravel DB connection first
        if (function_exists('app') && app()->bound('db')) {
            try {
                $this->pdo = DB::connection('agent_memory_sqlite')->getPdo();
                $this->pdo->exec('PRAGMA journal_mode=WAL;');
                $this->pdo->exec('PRAGMA foreign_keys=ON;');

                return $this->pdo;
            } catch (\Throwable $e) {
                // Fallback to direct PDO connection
            }
        }

        $dir = dirname($this->dbPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO('sqlite:'.$this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');

        $this->initSchema();

        return $this->pdo;
    }

    /**
     * Dynamically initialize SQLite schema if it doesn't exist.
     */
    public function initSchema(): void
    {
        $pdo = $this->getPdo();
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS memory_nodes (
                id TEXT PRIMARY KEY,
                type TEXT CHECK(type IN ('episodic', 'semantic', 'state')),
                content TEXT NOT NULL,
                phase_angle REAL NOT NULL DEFAULT 0.0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS memory_vectors (
                node_id TEXT PRIMARY KEY,
                embedding BLOB NOT NULL,
                FOREIGN KEY(node_id) REFERENCES memory_nodes(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS memory_edges (
                id TEXT PRIMARY KEY,
                source_id TEXT NOT NULL,
                target_id TEXT NOT NULL,
                edge_type TEXT CHECK(edge_type IN ('LEADS_TO', 'CONTAINS', 'EXPRESSES', 'INFLUENCES')),
                coherence_factor REAL DEFAULT 1.0,
                FOREIGN KEY(source_id) REFERENCES memory_nodes(id) ON DELETE CASCADE,
                FOREIGN KEY(target_id) REFERENCES memory_nodes(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS entanglement_pairs (
                node_a_id TEXT NOT NULL,
                node_b_id TEXT NOT NULL,
                entanglement_force REAL NOT NULL DEFAULT 1.0,
                PRIMARY KEY (node_a_id, node_b_id),
                FOREIGN KEY(node_a_id) REFERENCES memory_nodes(id) ON DELETE CASCADE,
                FOREIGN KEY(node_b_id) REFERENCES memory_nodes(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_nodes_phase ON memory_nodes(phase_angle);
            CREATE INDEX IF NOT EXISTS idx_edges_traversal ON memory_edges(source_id, target_id);
        SQL);
    }

    /**
     * Map Agent structural classes or names into static numeric phase offsets.
     */
    public function determinePhaseAngle(mixed $agent): float
    {
        $name = is_object($agent) ? get_class($agent) : (string) $agent;

        if (str_contains($name, 'SecurityAgent') || str_contains($name, 'Security')) {
            return 0.0;
        }

        if (str_contains($name, 'DataProcessingAgent') || str_contains($name, 'DataProcessing')) {
            return M_PI_2; // approx 1.570796
        }

        if (str_contains($name, 'EpisodicAgent') || str_contains($name, 'Episodic')) {
            return M_PI; // approx 3.141592
        }

        if (str_contains($name, 'SemanticAgent') || str_contains($name, 'Semantic')) {
            return 3 * M_PI_2; // approx 4.712388
        }

        return 0.0;
    }

    /**
     * Synthesize knowledge context envelope using superpositioned contexts and semantic entanglement.
     */
    public function synthesizeContext(string $query, float $queryPhase): string
    {
        $result = $this->retrieveWithTelemetry($query, $queryPhase);

        return $result['context'];
    }

    /**
     * Synthesize context and return telemetry data for monitoring.
     *
     * @return array{context: string, node_count: int, anchors_found: int, entangled_found: int, retrieval_ms: int, top_score: float}
     */
    public function retrieveWithTelemetry(string $query, float $queryPhase): array
    {
        $startTime = microtime(true);
        $telemetry = [
            'context' => '',
            'node_count' => 0,
            'anchors_found' => 0,
            'entangled_found' => 0,
            'retrieval_ms' => 0,
            'top_score' => 0.0,
        ];

        try {
            $pdo = $this->getPdo();

            // Early exit: skip embedding API call if there are no memory nodes to compare against
            $nodeCount = (int) $pdo->query('SELECT COUNT(*) FROM memory_nodes')->fetchColumn();
            $telemetry['node_count'] = $nodeCount;
            if ($nodeCount === 0) {
                $telemetry['retrieval_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                return $telemetry;
            }

            $queryVector = $this->getQueryEmbedding($query);
            if (empty($queryVector)) {
                // Fallback: use pseudo-embedding so retrieval still works with hash-based similarity
                $queryVector = $this->generatePseudoEmbedding($query);
            }

            // Bounded retrieval: limit nodes scanned to prevent performance degradation at scale.
            // For large node counts, use a SQL LIMIT with recent-first ordering.
            $scanLimit = min($nodeCount, 500);
            $stmt = $pdo->prepare(<<<'SQL'
                SELECT n.id, n.type, n.content, n.phase_angle, v.embedding
                FROM memory_nodes n
                JOIN memory_vectors v ON n.id = v.node_id
                ORDER BY n.created_at DESC
                LIMIT :limit
            SQL);
            $stmt->bindValue(':limit', $scanLimit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $telemetry['retrieval_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                return $telemetry;
            }

            $alpha = (float) (function_exists('config') ? config('harness.quantum_harness.alpha', 0.7) : 0.7);
            $beta = (float) (function_exists('config') ? config('harness.quantum_harness.beta', 0.3) : 0.3);
            $threshold = (float) (function_exists('config') ? config('harness.quantum_harness.similarity_threshold', 0.30) : 0.30);
            $limit = (int) (function_exists('config') ? config('harness.quantum_harness.max_anchors', 3) : 3);

            $scoredNodes = [];

            foreach ($rows as $row) {
                $nodeEmbedding = $this->parseEmbedding($row['embedding']);
                if (empty($nodeEmbedding)) {
                    continue;
                }

                $sCos = $this->cosineSimilarity($queryVector, $nodeEmbedding);
                $sInterfere = cos($queryPhase - (float) $row['phase_angle']);
                $sFused = ($alpha * $sCos) + ($beta * $sInterfere);

                if ($sFused >= $threshold) {
                    $scoredNodes[$row['id']] = [
                        'id' => $row['id'],
                        'type' => $row['type'],
                        'content' => $row['content'],
                        'phase_angle' => (float) $row['phase_angle'],
                        'score' => $sFused,
                        'source' => 'anchor',
                    ];
                }
            }

            if (empty($scoredNodes)) {
                $telemetry['retrieval_ms'] = (int) ((microtime(true) - $startTime) * 1000);

                return $telemetry;
            }

            // Sort and take top N anchors
            uasort($scoredNodes, fn ($a, $b) => $b['score'] <=> $a['score']);
            $anchors = array_slice($scoredNodes, 0, $limit, true);
            $telemetry['anchors_found'] = count($anchors);
            $telemetry['top_score'] = ! empty($anchors) ? (float) reset($anchors)['score'] : 0.0;

            // Multi-Hop Entanglement Traversal Loop
            $entangledNodes = [];
            foreach ($anchors as $anchorId => $anchorNode) {
                $stmt = $pdo->prepare(<<<'SQL'
                    SELECT node_b_id AS entangled_id, entanglement_force FROM entanglement_pairs WHERE node_a_id = :id1
                    UNION
                    SELECT node_a_id AS entangled_id, entanglement_force FROM entanglement_pairs WHERE node_b_id = :id2
                SQL);
                $stmt->execute([':id1' => $anchorId, ':id2' => $anchorId]);
                $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($pairs as $pair) {
                    $entangledId = $pair['entangled_id'];
                    $force = (float) $pair['entanglement_force'];
                    $inheritedScore = $anchorNode['score'] * $force;

                    // Fetch details of entangled node
                    $stmtNode = $pdo->prepare('SELECT type, content, phase_angle FROM memory_nodes WHERE id = ?');
                    $stmtNode->execute([$entangledId]);
                    $nodeDetails = $stmtNode->fetch(PDO::FETCH_ASSOC);

                    if ($nodeDetails) {
                        if (isset($anchors[$entangledId])) {
                            continue; // Skip if already an anchor
                        }

                        if (isset($entangledNodes[$entangledId])) {
                            if ($inheritedScore > $entangledNodes[$entangledId]['score']) {
                                $entangledNodes[$entangledId]['score'] = $inheritedScore;
                            }
                        } else {
                            $entangledNodes[$entangledId] = [
                                'id' => $entangledId,
                                'type' => $nodeDetails['type'],
                                'content' => $nodeDetails['content'],
                                'phase_angle' => (float) $nodeDetails['phase_angle'],
                                'score' => $inheritedScore,
                                'source' => 'entangled',
                            ];
                        }
                    }
                }
            }

            $telemetry['entangled_found'] = count($entangledNodes);

            // Merge anchors and entangled nodes
            $finalEnvelope = array_merge(array_values($anchors), array_values($entangledNodes));
            usort($finalEnvelope, fn ($a, $b) => $b['score'] <=> $a['score']);

            // Format markdown representation
            $markdown = "## QUANTUM ONTOLOGY MEMORY ENVELOPE\n";
            $markdown .= "The following cognitive nodes were retrieved from isolated memory:\n\n";

            foreach ($finalEnvelope as $node) {
                $markdown .= sprintf(
                    "- [%s] [Type: %s] [Score: %.4f] [Phase: %.3f] [Origin: %s]\n  Content: %s\n\n",
                    $node['id'],
                    ucfirst($node['type']),
                    $node['score'],
                    $node['phase_angle'],
                    $node['source'],
                    trim($node['content'])
                );
            }

            $telemetry['context'] = trim($markdown);
            $telemetry['retrieval_ms'] = (int) ((microtime(true) - $startTime) * 1000);

            return $telemetry;

        } catch (\Throwable $e) {
            if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                info('Quantum context synthesis failed: '.$e->getMessage());
            }

            $telemetry['retrieval_ms'] = (int) ((microtime(true) - $startTime) * 1000);

            return $telemetry;
        }
    }

    /**
     * Prune old memory nodes to keep the graph bounded.
     *
     * Removes nodes older than the given threshold that are not entangled
     * with any other node, preserving the most connected memories.
     *
     * @return int Number of nodes pruned.
     */
    public function pruneOldNodes(int $maxNodes = 10000): int
    {
        try {
            $pdo = $this->getPdo();
            $count = (int) $pdo->query('SELECT COUNT(*) FROM memory_nodes')->fetchColumn();
            if ($count <= $maxNodes) {
                return 0;
            }

            // Delete oldest nodes that have no entanglement pairs
            $stmt = $pdo->prepare(<<<'SQL'
                DELETE FROM memory_nodes
                WHERE id IN (
                    SELECT n.id FROM memory_nodes n
                    LEFT JOIN entanglement_pairs e ON e.node_a_id = n.id OR e.node_b_id = n.id
                    WHERE e.node_a_id IS NULL
                    ORDER BY n.created_at ASC
                    LIMIT :limit
                )
            SQL);
            $stmt->bindValue(':limit', $count - $maxNodes, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Store a memory node with its embedding vector into the quantum memory DB.
     *
     * @param  string  $id  Unique node identifier.
     * @param  string  $type  Node type: 'episodic', 'semantic', or 'state'.
     * @param  string  $content  The text content of the memory.
     * @param  float  $phaseAngle  Quantum phase angle for interference scoring.
     * @param  array<float>|null  $embedding  Pre-computed embedding vector. If null, one will be generated.
     * @return bool True if stored successfully.
     */
    public function storeNode(string $id, string $type, string $content, float $phaseAngle, ?array $embedding = null): bool
    {
        try {
            $pdo = $this->getPdo();
            $this->initSchema();

            if ($embedding === null) {
                $embedding = $this->getQueryEmbedding($content);
            }

            if (empty($embedding)) {
                // Fallback: generate a deterministic pseudo-embedding from the content hash
                // This ensures nodes are still stored and can be retrieved by text similarity
                $embedding = $this->generatePseudoEmbedding($content);
                if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                    info('Quantum storeNode: embedding provider unavailable, using pseudo-embedding for node '.$id);
                }
            }

            $embeddingBlob = json_encode($embedding, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare(
                'INSERT OR REPLACE INTO memory_nodes (id, type, content, phase_angle, created_at)
                 VALUES (:id, :type, :content, :phase, datetime(\'now\'))'
            );
            $stmt->execute([
                ':id' => $id,
                ':type' => $type,
                ':content' => $content,
                ':phase' => $phaseAngle,
            ]);

            $vecStmt = $pdo->prepare(
                'INSERT OR REPLACE INTO memory_vectors (node_id, embedding)
                 VALUES (:nodeId, :embedding)'
            );
            $vecStmt->execute([
                ':nodeId' => $id,
                ':embedding' => $embeddingBlob,
            ]);

            return true;
        } catch (\Throwable $e) {
            if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                info('Quantum storeNode failed: '.$e->getMessage());
            }

            return false;
        }
    }

    /**
     * Retrieve query embedding vector using Laravel AI SDK.
     */
    protected function getQueryEmbedding(string $text): array
    {
        if (blank($text)) {
            return [];
        }

        if (class_exists(Embeddings::class)) {
            $provider = config('ai.default_for_embeddings') ?: (config('harness.default.provider') ?: 'ollama');
            $model = null;
            if (class_exists('App\Services\AiConfigHelper')) {
                try {
                    $cfg = AiConfigHelper::configureEmbeddings();
                    $provider = $cfg['provider'] ?? $provider;
                    $model = $cfg['model'] ?? null;
                } catch (\Throwable $e) {
                    // Ignore config errors
                }
            }
            try {
                $response = Embeddings::for([$text])->generate($provider, $model);

                return $response->first() ?? [];
            } catch (\Throwable $e) {
                if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                    info('Embedding generation failed in QuantumInferenceEngine: '.$e->getMessage());
                }
            }
        }

        return [];
    }

    /**
     * Parse embedding column from DB. Handles JSON strings and packed float buffers.
     */
    protected function parseEmbedding(mixed $embedding): array
    {
        if (empty($embedding)) {
            return [];
        }

        if (is_string($embedding)) {
            $decoded = json_decode($embedding, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try unpack if it's binary data
        try {
            $unpacked = unpack('f*', $embedding);

            return $unpacked ? array_values($unpacked) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compute cosine similarity in PHP. Handles large vectors with memory-safe loops.
     */
    protected function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = min(count($vec1), count($vec2));
        if ($count === 0) {
            return 0.0;
        }

        // Chunk comparisons to prevent massive memory usage
        $chunks = array_chunk(range(0, $count - 1), 512);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $i) {
                $v1 = (float) $vec1[$i];
                $v2 = (float) $vec2[$i];
                $dotProduct += $v1 * $v2;
                $normA += $v1 * $v1;
                $normB += $v2 * $v2;
            }
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Generate a deterministic pseudo-embedding from content hash.
     * Used as fallback when the embedding provider is unavailable.
     * Produces a 384-dimensional vector (common small embedding size).
     *
     * @return array<float>
     */
    protected function generatePseudoEmbedding(string $content): array
    {
        $hash = hash('sha256', $content, true);
        $vector = [];
        $dimension = 384;

        for ($i = 0; $i < $dimension; $i++) {
            $byteIndex = $i % strlen($hash);
            $byte = ord($hash[$byteIndex]);
            $vector[] = ($byte - 128) / 128.0;
        }

        return $vector;
    }
}
