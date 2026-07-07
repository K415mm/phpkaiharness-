<?php

namespace Phpkaiharness\Optimize;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Cognitive Memory & Property Graphs extractor.
 * Automatically runs a post-execution LLM call to extract key facts/rules
 * and stores them in the persistent SQLite graph memory table.
 */
class CognitiveGraphMemory
{
    /** @var array<string> Patterns that indicate low-quality facts */
    private array $rejectPatterns;

    private int $minLength;

    private bool $rejectMarkdownOnly;

    private float $dedupThreshold;

    private bool $dedupEnabled;

    public function __construct()
    {
        $qualityConfig = function_exists('config') ? config('harness.cognitive_memory.quality_filter', []) : [];
        $dedupConfig = function_exists('config') ? config('harness.cognitive_memory.dedup', []) : [];

        $this->rejectPatterns = $qualityConfig['reject_patterns'] ?? [
            'maybe', 'might', 'possibly', 'i think', 'not sure', 'unclear',
            'todo', 'tbd', 'fixme', 'hack', 'placeholder', 'lorem ipsum',
            'test data', 'dummy', 'sample', 'example', 'placeholder text',
        ];
        $this->minLength = $qualityConfig['min_length'] ?? 15;
        $this->rejectMarkdownOnly = $qualityConfig['reject_markdown_only'] ?? true;
        $this->dedupEnabled = $dedupConfig['enabled'] ?? true;
        $this->dedupThreshold = $dedupConfig['similarity_threshold'] ?? 0.85;
    }
    /**
     * Parse the agent prompt and response, extract key facts, and store them.
     */
    public function extractAndStore(
        string $sessionId,
        string $prompt,
        string $response,
        LlmClientInterface $client,
        ?AnalyticsCollectorInterface $collector
    ): void {
        $enabled = HarnessConfig::isNodeEnabled('cognitive_memory', 'harness.cognitive_memory.enabled', true);
        if (! $enabled || ! $collector) {
            return;
        }

        try {
            $systemPrompt = "You are a precise facts-extraction assistant. Your job is to extract a flat list of key facts or state changes from the given interaction transcript.\n".
                "Focus on concrete changes (e.g. settings updated, client added, allocations changed, device counts set).\n".
                "Rules:\n".
                "1. Each fact must be a single, standalone sentence on its own line.\n".
                "2. Do NOT output markdown formatting, lists, numbers, bullets, or headers.\n".
                '3. If no concrete facts or settings were resolved/updated, output nothing.';

            $transcript = "### User Task:\n{$prompt}\n\n### Agent Final Response:\n{$response}";

            $llmResponse = $client->chat(
                systemPrompt: $systemPrompt,
                messages: [['role' => 'user', 'content' => $transcript]],
                tools: [],
                model: $client->getResolvedModel(),
                sessionId: $sessionId,
                collector: null // Don't log this internal extraction as an LLM call step in telemetry
            );

            $text = trim($llmResponse['content'] ?? '');
            if (empty($text)) {
                if ($collector) {
                    $collector->recordEvent(
                        $sessionId,
                        'cognitive_memory',
                        'CognitiveGraphMemory',
                        ['facts_count' => 0],
                        'No facts extracted: LLM returned empty response.'
                    );
                }

                return;
            }

            $lines = explode("\n", $text);
            $facts = [];
            $skipped = 0;
            $deduped = 0;

            foreach ($lines as $line) {
                $trimmed = trim(preg_replace('/^[\s\-\*•\d\.]+\s*/', '', trim($line)));
                if (! $this->passesQualityFilter($trimmed)) {
                    $skipped++;
                    continue;
                }

                // Dedup check against existing facts
                if ($this->dedupEnabled && $collector instanceof SqliteMonitorStore) {
                    $similar = $collector->findSimilarFacts($trimmed, 3);
                    if (! empty($similar)) {
                        $isDuplicate = false;
                        foreach ($similar as $existing) {
                            similar_text($trimmed, $existing['fact'], $percent);
                            if ($percent / 100 >= $this->dedupThreshold) {
                                $isDuplicate = true;
                                $deduped++;
                                break;
                            }
                        }
                        if ($isDuplicate) {
                            continue;
                        }
                    }
                }

                $facts[] = $trimmed;
                if ($collector instanceof SqliteMonitorStore) {
                    $collector->recordFactWithMetadata($sessionId, $trimmed, [
                        'confidence' => 1.0,
                        'category' => $this->categorizeFact($trimmed),
                        'source_type' => 'agent',
                        'source_id' => $sessionId,
                    ]);
                } else {
                    $collector->recordFact($sessionId, $trimmed);
                }
            }

            $collector->recordEvent(
                $sessionId,
                'cognitive_memory',
                'CognitiveGraphMemory',
                ['facts_count' => count($facts), 'skipped' => $skipped, 'deduped' => $deduped],
                'Extracted '.count($facts).' facts (skipped '.$skipped.', deduped '.$deduped.') and updated the persistent cognitive graph memory.'
            );
        } catch (\Throwable $e) {
            if ($collector) {
                $collector->recordEvent(
                    $sessionId,
                    'cognitive_memory',
                    'CognitiveGraphMemory',
                    ['facts_count' => 0, 'error' => $e->getMessage()],
                    'Cognitive memory extraction failed: '.$e->getMessage()
                );
            }
        }
    }

    /**
     * Check if a fact passes quality filters.
     */
    public function passesQualityFilter(string $fact): bool
    {
        if (empty($fact) || mb_strlen($fact) < $this->minLength) {
            return false;
        }

        $lower = strtolower($fact);
        foreach ($this->rejectPatterns as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return false;
            }
        }

        // Reject markdown-only lines (headers, separators, etc.)
        if ($this->rejectMarkdownOnly) {
            $stripped = trim(preg_replace('/[#*_`>\-|=]+/', '', $fact));
            if (empty($stripped)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Categorize a fact based on its content.
     */
    private function categorizeFact(string $fact): string
    {
        $lower = strtolower($fact);
        if (preg_match('/\b(set|updated|changed|modified|configured)\b/', $lower)) {
            return 'setting_change';
        }
        if (preg_match('/\b(created|added|inserted|new)\b/', $lower)) {
            return 'creation';
        }
        if (preg_match('/\b(deleted|removed|dropped|purged)\b/', $lower)) {
            return 'deletion';
        }
        if (preg_match('/\b(allocated|assigned|distributed|budget)\b/', $lower)) {
            return 'allocation';
        }
        if (preg_match('/\b(error|failed|warning|issue)\b/', $lower)) {
            return 'error';
        }

        return 'general';
    }
}
