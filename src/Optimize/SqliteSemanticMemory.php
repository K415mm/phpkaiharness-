<?php

namespace Phpkaiharness\Optimize;

use App\Services\AiConfigHelper;
use Laravel\Ai\Embeddings;
use PDO;
use Phpkaiharness\Contracts\SemanticMemoryInterface;

class SqliteSemanticMemory implements SemanticMemoryInterface
{
    private PDO $pdo;

    private string $dbPath;

    public function __construct(PDO $pdo, string $dbPath)
    {
        $this->pdo = $pdo;
        $this->dbPath = $dbPath;
        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS harness_memories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                text TEXT NOT NULL,
                embedding TEXT NOT NULL, -- JSON array of floats
                source TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_memories_source ON harness_memories(source);
        SQL);
    }

    /**
     * Query semantic memories / document chunks relevant to a given prompt.
     *
     * @return array<array{text: string, source: string, score: float}>
     */
    public function search(string $query, float $threshold = 0.30, int $limit = 3): array
    {
        $queryVector = $this->getEmbedding($query);
        if (empty($queryVector)) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT text, embedding, source FROM harness_memories');
        $memories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scored = [];
        foreach ($memories as $mem) {
            $embedding = json_decode($mem['embedding'], true);
            if (! is_array($embedding)) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $embedding);
            if ($score >= $threshold) {
                $scored[] = [
                    'text' => $mem['text'],
                    'source' => $mem['source'],
                    'score' => (float) $score,
                ];
            }
        }

        // Sort by score descending
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Persist a text chunk together with its pre-computed embedding vector.
     */
    public function addMemory(string $text, array $embedding, string $source): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO harness_memories (text, embedding, source, created_at)
             VALUES (:text, :embedding, :source, datetime('now'))"
        );
        $stmt->execute([
            ':text' => $text,
            ':embedding' => json_encode($embedding, JSON_UNESCAPED_SLASHES),
            ':source' => $source,
        ]);
    }

    protected function getEmbedding(string $text): array
    {
        if (class_exists(Embeddings::class)) {
            $provider = config('ai.default_for_embeddings');
            if (empty($provider)) {
                $provider = config('harness.default.provider', 'ollama');
            }
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
                throw new \RuntimeException('Failed to generate embedding via Laravel AI SDK: '.$e->getMessage(), 0, $e);
            }
        }

        throw new \RuntimeException('Laravel AI SDK (Laravel\\Ai\\Embeddings) not available for generating embeddings.');
    }

    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = min(count($vec1), count($vec2));
        if ($count === 0) {
            return 0.0;
        }

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $normA += $vec1[$i] * $vec1[$i];
            $normB += $vec2[$i] * $vec2[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
