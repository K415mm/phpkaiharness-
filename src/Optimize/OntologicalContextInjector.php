<?php

namespace Phpkaiharness\Optimize;

use App\Services\AiConfigHelper;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Palantir-style RAG injector. Uses Laravel AI SDK Embeddings to find
 * semantically relevant Eloquent records and inject them as system context.
 */
class OntologicalContextInjector
{
    /**
     * Generate embeddings for the prompt query, search for relevant DB records of the target Eloquent model,
     * and inject them into the system prompt context.
     *
     * @param  AgentPrompt  $prompt  The active AgentPrompt.
     * @param  string  $eloquentModelClass  The fully qualified class name of the target Eloquent Model.
     * @param  string  $embeddingColumn  The DB column holding the vector embeddings (default: 'embedding').
     * @param  float  $threshold  Cosine similarity threshold (default: 0.30).
     * @param  int  $limit  Max records to retrieve (default: 3).
     */
    public function inject(
        AgentPrompt $prompt,
        string $eloquentModelClass,
        string $embeddingColumn = 'embedding',
        float $threshold = 0.30,
        int $limit = 3,
        ?array &$metadata = null
    ): AgentPrompt {
        $query = $prompt->prompt;

        if ($metadata !== null) {
            $metadata = [
                'model_class' => $eloquentModelClass,
                'embedding_column' => $embeddingColumn,
                'threshold' => $threshold,
                'limit' => $limit,
                'embedding_provider' => 'unknown',
                'evaluated_records' => [],
                'injected_context' => '',
                'error' => null,
            ];
        }

        if (blank($query) || ! class_exists($eloquentModelClass)) {
            if ($metadata !== null && ! class_exists($eloquentModelClass)) {
                $metadata['error'] = "Class '{$eloquentModelClass}' does not exist.";
            }

            return $prompt;
        }

        try {
            // Configure embeddings from app settings and get provider + model
            $embeddingConfig = [];
            if (class_exists('App\Services\AiConfigHelper')) {
                $embeddingConfig = AiConfigHelper::configureEmbeddings();
            }
            $provider = $embeddingConfig['provider'] ?? config('ai.default_for_embeddings', 'ollama');
            $model = $embeddingConfig['model'] ?? null;
            if ($metadata !== null) {
                $metadata['embedding_provider'] = $provider;
                $metadata['embedding_model'] = $model;
            }

            // Generate query vector using Laravel AI SDK — pass model explicitly
            $response = Embeddings::for([$query])->generate($provider, $model);
            $queryVector = $response->first();

            if (empty($queryVector)) {
                if ($metadata !== null) {
                    $metadata['error'] = 'Failed to generate query embedding vector.';
                }

                return $prompt;
            }

            // Always use in-memory similarity search — NEVER query PostgreSQL or host app DB.
            // phpkaiharness is a standalone package and must only use SQLite.
            $records = collect();
            $evaluated = [];

            if (method_exists($eloquentModelClass, 'all')) {
                $allRecords = $eloquentModelClass::all();
                $hasSimilarityMethod = $allRecords->isNotEmpty() && method_exists($allRecords->first(), 'similarity');
                $hasEmbeddingColumn = $allRecords->isNotEmpty()
                    && method_exists($allRecords->first(), 'getAttribute')
                    && $allRecords->first()->getAttribute($embeddingColumn) !== null;

                $records = $allRecords->map(function ($record) use ($queryVector, &$evaluated, $hasSimilarityMethod, $provider, $model, $query) {
                    $similarity = 0.0;

                    if ($hasSimilarityMethod) {
                        $similarity = (float) $record->similarity($queryVector);
                    } else {
                        // Attempt embedding cosine similarity
                        $recordText = $this->recordToText($record);
                        if (! empty($recordText)) {
                            try {
                                $recordResponse = Embeddings::for([$recordText])->generate($provider, $model);
                                $recordVector = $recordResponse->first();
                                if (! empty($recordVector)) {
                                    $similarity = $this->cosineSimilarity($queryVector, $recordVector);
                                }
                            } catch (\Throwable $e) {
                                // Embedding failed — fallback to text overlap score
                            }

                            // Fallback: Token overlap similarity (Jaccard) for robust context matching
                            if ($similarity <= 0.05) {
                                $queryTokens = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $query))));
                                $recordTokens = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $recordText))));
                                if (! empty($queryTokens) && ! empty($recordTokens)) {
                                    $intersection = count(array_intersect($queryTokens, $recordTokens));
                                    $union = count(array_unique(array_merge($queryTokens, $recordTokens)));
                                    if ($union > 0) {
                                        $similarity = round($intersection / $union, 4);
                                    }
                                }
                            }
                        }
                    }

                    $evaluated[] = [
                        'id' => $record->id ?? 'N/A',
                        'score' => round($similarity, 4),
                        'preview' => method_exists($record, 'toArray') ? array_slice($record->toArray(), 0, 4) : null,
                    ];

                    return [
                        'record' => $record,
                        'score' => $similarity,
                    ];
                })
                    ->filter(fn ($item) => $item['score'] >= $threshold)
                    ->sortByDesc('score')
                    ->take($limit)
                    ->map(fn ($item) => $item['record']);
            }

            if ($metadata !== null) {
                $metadata['evaluated_records'] = $evaluated;
            }

            if ($records->isNotEmpty()) {
                $injection = "\n## ONTOLOGICAL CONTEXT SECTION\n".
                    "The following semantically relevant records from the Ontology layer were retrieved:\n";

                foreach ($records as $record) {
                    $injection .= "\n- [Record ID: ".($record->id ?? 'N/A').'] '.json_encode($record->toArray())."\n";
                }

                if ($metadata !== null) {
                    $metadata['injected_context'] = $injection;
                }

                return $prompt->append($injection);
            }
        } catch (\Throwable $e) {
            if ($metadata !== null) {
                $metadata['error'] = $e->getMessage();
            }
            // Silently log and ignore to prevent AI loop crashes
            if (function_exists('info') && function_exists('app') && app()->bound('log')) {
                info('Ontological context injection failed: '.$e->getMessage());
            }
        }

        return $prompt;
    }

    /**
     * Convert an Eloquent record to a text representation for embedding generation.
     */
    protected function recordToText(mixed $record): string
    {
        if (! method_exists($record, 'toArray')) {
            return '';
        }

        $data = $record->toArray();
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $parts[] = "{$key}: {$value}";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Compute cosine similarity between two vectors.
     *
     * @param  array<float>  $vec1
     * @param  array<float>  $vec2
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

        for ($i = 0; $i < $count; $i++) {
            $v1 = (float) ($vec1[$i] ?? 0);
            $v2 = (float) ($vec2[$i] ?? 0);
            $dotProduct += $v1 * $v2;
            $normA += $v1 * $v1;
            $normB += $v2 * $v2;
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
