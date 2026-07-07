<?php

namespace Phpkaiharness\Optimize;

use Phpkaiharness\Contracts\LlmClientInterface;

/**
 * Context window management and message compaction.
 *
 * Prevents token overflow by pruning old turns (sliding window) or
 * collapsing history into summaries via LLM utility calls.
 */
class ContextCompactor
{
    private string $strategy;

    private int $maxTurns;

    private int $maxTokensThreshold;

    /** @var array{compacted: bool, turns_before: int, turns_after: int, tokens_before: int, tokens_after: int, strategy: string} */
    private array $lastTelemetry;

    public function __construct(
        string $strategy = 'sliding_window',
        int $maxTurns = 6,
        int $maxTokensThreshold = 4000
    ) {
        $this->strategy = $strategy;
        $this->maxTurns = $maxTurns;
        $this->maxTokensThreshold = $maxTokensThreshold;
        $this->lastTelemetry = [
            'compacted' => false,
            'turns_before' => 0,
            'turns_after' => 0,
            'tokens_before' => 0,
            'tokens_after' => 0,
            'strategy' => $strategy,
        ];
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Get telemetry data from the last compaction operation.
     *
     * @return array{compacted: bool, turns_before: int, turns_after: int, tokens_before: int, tokens_after: int, strategy: string}
     */
    public function getLastTelemetry(): array
    {
        return $this->lastTelemetry;
    }

    /**
     * Estimate token count for a message history.
     * Uses ~4 chars per token approximation (standard for most tokenizers).
     */
    public function estimateTokens(array $history): int
    {
        $totalChars = 0;
        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            if (is_string($content)) {
                $totalChars += strlen($content);
            } elseif (is_array($content)) {
                $totalChars += strlen(json_encode($content));
            }
            if (! empty($msg['tool_calls'])) {
                $totalChars += strlen(json_encode($msg['tool_calls']));
            }
        }

        return (int) ceil($totalChars / 4);
    }

    /**
     * Compact the conversation history in-place.
     */
    public function compact(
        array &$history,
        string $systemPrompt,
        string $model,
        ?LlmClientInterface $llmClient = null
    ): array {
        $turnsBefore = count($history);
        $tokensBefore = $this->estimateTokens($history);

        $this->lastTelemetry = [
            'compacted' => false,
            'turns_before' => $turnsBefore,
            'turns_after' => $turnsBefore,
            'tokens_before' => $tokensBefore,
            'tokens_after' => $tokensBefore,
            'strategy' => $this->strategy,
        ];

        // Token-aware check: if estimated tokens exceed threshold, force compaction
        $needsCompaction = count($history) > $this->maxTurns || $tokensBefore > $this->maxTokensThreshold;

        if (! $needsCompaction) {
            return $history;
        }

        if ($this->strategy === 'summarize' && $llmClient !== null) {
            $result = $this->compactWithSummary($history, $systemPrompt, $model, $llmClient);
        } else {
            $result = $this->compactWithSlidingWindow($history);
        }

        $this->lastTelemetry['compacted'] = true;
        $this->lastTelemetry['turns_after'] = count($history);
        $this->lastTelemetry['tokens_after'] = $this->estimateTokens($history);

        return $result;
    }

    /**
     * Prune messages between the initial prompt and the last N turns.
     */
    private function compactWithSlidingWindow(array &$history): array
    {
        $total = count($history);
        if ($total <= 3) {
            return $history;
        }

        // Keep the root request (usually index 0)
        $rootUserQuery = $history[0];

        // Keep the last maxTurns messages
        $lastMessages = array_slice($history, -$this->maxTurns);

        // Reconstruct history: [rootUserQuery] + [pruned indicator] + [lastMessages]
        $newHistory = [$rootUserQuery];

        // If we dropped messages, insert a marker
        $droppedCount = $total - 1 - count($lastMessages);
        if ($droppedCount > 0) {
            $newHistory[] = [
                'role' => 'system',
                'content' => "⚠️ [Context Compacter: Dropped {$droppedCount} older intermediate tool logs/turns to fit context window]",
            ];
        }

        foreach ($lastMessages as $msg) {
            $newHistory[] = $msg;
        }

        $history = $newHistory;

        return $history;
    }

    /**
     * Summarize old turns using the LLM and place them into a single system instruction.
     */
    private function compactWithSummary(
        array &$history,
        string $systemPrompt,
        string $model,
        LlmClientInterface $llmClient
    ): array {
        $total = count($history);
        if ($total <= 4) {
            return $history;
        }

        // We summarize everything EXCEPT the root user query (index 0) and the last 2 turns
        $turnsToSummarize = array_slice($history, 1, $total - 3);
        $lastTurns = array_slice($history, -2);

        // Construct a prompt to summarize the turns
        $summaryPrompt = "Summarize the execution steps, findings, and logs from this trace:\n\n";
        foreach ($turnsToSummarize as $turn) {
            $role = strtoupper($turn['role']);
            $content = $turn['content'] ?? '';
            if (! empty($turn['tool_calls'])) {
                $content .= ' (Triggered tool calls: '.json_encode($turn['tool_calls']).')';
            }
            $summaryPrompt .= "[{$role}]: {$content}\n";
        }

        try {
            $response = $llmClient->chat(
                systemPrompt: 'You are a context summarizer. Write a brief 1-2 sentence overview of the actions taken and results discovered. Be extremely concise.',
                messages: [['role' => 'user', 'content' => $summaryPrompt]],
                tools: [],
                model: $model
            );

            $summaryText = $response['content'] ?? 'Agent executed initial diagnostics steps.';

            // Rebuild history
            $newHistory = [
                $history[0], // Keep original prompt
                [
                    'role' => 'system',
                    'content' => "Summary of previous research steps: {$summaryText}",
                ],
            ];

            foreach ($lastTurns as $turn) {
                $newHistory[] = $turn;
            }

            $history = $newHistory;
        } catch (\Exception $e) {
            // Fall back to sliding window on failure
            $this->compactWithSlidingWindow($history);
        }

        return $history;
    }
}
