<?php

namespace Phpkaiharness\Contracts;

interface SemanticMemoryInterface
{
    /**
     * Query semantic memories / document chunks relevant to a given prompt.
     *
     * @param  string  $query  The query prompt or text to match against stored memories.
     * @param  float  $threshold  Minimum cosine-similarity score (0.0–1.0) to include a result.
     * @param  int  $limit  Maximum number of chunks to return.
     * @return array<array{text: string, source: string, score: float}>
     */
    public function search(string $query, float $threshold = 0.30, int $limit = 3): array;

    /**
     * Persist a text chunk together with its pre-computed embedding vector.
     *
     * @param  string  $text  Raw text content of the memory chunk.
     * @param  array<float>  $embedding  Mathematical vector representation of the text.
     * @param  string  $source  File name, URL, or identifier of the origin document.
     */
    public function addMemory(string $text, array $embedding, string $source): void;
}
