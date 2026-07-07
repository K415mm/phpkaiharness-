<?php

namespace Phpkaiharness\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Palantir-style policy guardrail middleware for laravel/ai agents.
 * Validates prompts and LLM-generated tool calls against host application authorization policies.
 */
class PolicyGuardrailMiddleware
{
    /**
     * Handle the incoming prompt and validate outgoing tool calls.
     *
     * @throws AuthorizationException
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $enabled = HarnessConfig::isNodeEnabled('policy_guardrail', 'harness.policy_guardrail.enabled', true);
        if (! $enabled) {
            return $next($prompt);
        }

        $sessionId = function_exists('app') && app()->bound('harness.active_session_id') ? app('harness.active_session_id') : null;
        $collector = null;
        if (function_exists('app')) {
            try {
                if (app()->bound(AnalyticsCollectorInterface::class)) {
                    $collector = app(AnalyticsCollectorInterface::class);
                } elseif (function_exists('config') && function_exists('app') && app()->bound('config')) {
                    $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();
                    $collector = new SqliteMonitorStore($dbPath);
                }
            } catch (\Throwable $e) {
            }
        }

        // Record a default policy check pass event so telemetry has a record of execution
        if ($collector && $sessionId) {
            $collector->recordEvent(
                $sessionId,
                'policy_guardrail',
                'PolicyGuardrailMiddleware',
                ['auth_checked' => function_exists('auth')],
                'Allowed: Request passed initial policy authorization gate.'
            );
        }

        // 1. Verify user permission to use the agent
        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            if (method_exists($user, 'can') && ! $user->can('use-ai-agents')) {
                if ($collector && $sessionId) {
                    $collector->recordEvent(
                        $sessionId,
                        'policy_guardrail',
                        'user_auth_check',
                        ['user_id' => $user->id ?? null],
                        'Blocked: User is not authorized to execute agent loops.'
                    );
                }
                throw new AuthorizationException('User is not authorized to execute agent loops.');
            } else {
                if ($collector && $sessionId) {
                    $collector->recordEvent(
                        $sessionId,
                        'policy_guardrail',
                        'user_auth_check',
                        ['user_id' => $user->id ?? null],
                        'Allowed: User authorized to run agents.'
                    );
                }
            }
        }

        // 2. Execute the chain to get the agent's response
        $response = $next($prompt);

        // 3. Post-execution: Validate any generated tool calls
        if (isset($response->toolCalls) && is_iterable($response->toolCalls)) {
            foreach ($response->toolCalls as $call) {
                $toolName = $call->name ?? '';
                // If a gate is defined in the host app for the tool name, run the check
                if (class_exists(Gate::class) && Gate::has("execute-tool-{$toolName}")) {
                    $args = $call->arguments ?? [];
                    if (! Gate::allows("execute-tool-{$toolName}", [$args])) {
                        if ($collector && $sessionId) {
                            $collector->recordEvent(
                                $sessionId,
                                'policy_guardrail',
                                'tool_authorization',
                                ['tool' => $toolName, 'arguments' => $args],
                                "Blocked: Unauthorized tool call execution: {$toolName}"
                            );
                        }
                        throw new AuthorizationException("Unauthorized tool call execution: {$toolName}");
                    } else {
                        if ($collector && $sessionId) {
                            $collector->recordEvent(
                                $sessionId,
                                'policy_guardrail',
                                'tool_authorization',
                                ['tool' => $toolName, 'arguments' => $args],
                                "Allowed: Authorized tool call execution: {$toolName}"
                            );
                        }
                    }
                }
            }
        }

        return $response;
    }
}
