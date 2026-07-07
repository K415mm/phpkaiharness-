<?php

namespace Phpkaiharness\Llm;

use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;

/**
 * Decorator that tracks accumulated token usage (prompt + completion tokens) in the session.
 * Inject warning instructions into the system prompt once token budget is exceeded.
 */
class ThinkingBudgetLlmClient implements LlmClientInterface
{
    public function __construct(
        protected LlmClientInterface $innerClient,
        protected int $maxTokensBudget = 30000
    ) {}

    /**
     * Delegate chat call, and inject budget enforcement warning if tokens exceed budget.
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        string $model = '',
        ?string $sessionId = null,
        ?AnalyticsCollectorInterface $collector = null,
        ?callable $onChunk = null
    ): array {
        $enabled = (function_exists('config') && function_exists('app') && app()->bound('config')) ? (bool) config('harness.budget.enabled', config('harness.thinking_budget.enabled', true)) : true;
        if (! $enabled) {
            return $this->innerClient->chat($systemPrompt, $messages, $tools, $model, $sessionId, $collector, $onChunk);
        }

        $limit = (function_exists('config') && function_exists('app') && app()->bound('config')) ? (int) config('harness.budget.max_tokens', config('harness.thinking_budget.max_tokens', config('harness.thinking_budget.max_thinking_tokens', $this->maxTokensBudget))) : $this->maxTokensBudget;

        $totalTokens = 0;
        if ($sessionId) {
            $totalTokens = $this->getSessionTokenCount($sessionId);
        }

        $effectiveSystemPrompt = $systemPrompt;
        if ($totalTokens > $limit) {
            $warning = "\n\n⚠️ SYSTEM BUDGET WARNING: You have consumed {$totalTokens} tokens, which exceeds your thinking budget of {$limit} tokens. Do NOT invoke any further tools. Conclude your thoughts and write your final response now.";
            $effectiveSystemPrompt .= $warning;

            if ($collector && $sessionId) {
                $collector->recordEvent(
                    $sessionId,
                    'rate_limit',
                    'ThinkingBudgetLlmClient',
                    ['accumulated_tokens' => $totalTokens, 'token_limit' => $limit],
                    'Thinking budget exceeded: injected termination warning to the LLM system prompt.'
                );
            }
        } else {
            // Record active monitoring event to show that the budget was checked and was fine
            if ($collector && $sessionId) {
                $collector->recordEvent(
                    $sessionId,
                    'rate_limit',
                    'ThinkingBudgetLlmClient',
                    ['accumulated_tokens' => $totalTokens, 'token_limit' => $limit],
                    'Checked: Token usage is within budget. Remaining budget: '.($limit - $totalTokens).' tokens.'
                );
            }
        }

        return $this->innerClient->chat(
            $effectiveSystemPrompt,
            $messages,
            $tools,
            $model,
            $sessionId,
            $collector,
            $onChunk
        );
    }

    /**
     * Query the monitor store directly to sum the tokens used in the current session.
     */
    protected function getSessionTokenCount(string $sessionId): int
    {
        try {
            $dbPath = (function_exists('config') && function_exists('app') && app()->bound('config')) ? config('harness.cache.db_path', config('harness.semantic_cache.db_path')) : null;
            $dbPath = $dbPath ?: SqliteMonitorStore::defaultDbPath();

            if (file_exists($dbPath)) {
                $pdo = new \PDO('sqlite:'.$dbPath);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(tokens_prompt + tokens_completion), 0)
                     FROM harness_details
                     WHERE session_id = :sid AND type = 'llm_call'"
                );
                $stmt->execute([':sid' => $sessionId]);

                return (int) $stmt->fetchColumn();
            }
        } catch (\Throwable $e) {
            // Suppress connection errors
        }

        return 0;
    }

    /**
     * Resolve model name.
     */
    public function getResolvedModel(): string
    {
        return $this->innerClient->getResolvedModel();
    }
}
