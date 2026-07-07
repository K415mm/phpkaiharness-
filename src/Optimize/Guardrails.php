<?php

namespace Phpkaiharness\Optimize;

/**
 * Safety validation with Palantir AIP-inspired governance controls.
 *
 * Features:
 * - Pattern-based safety scanning (command injection, dangerous commands)
 * - Purpose-based scope controls (authorized functional domains)
 * - High-risk tool approval system (human-in-the-loop checkpoints)
 */
class Guardrails
{
    private array $blacklistPatterns;

    /** @var callable|null Human approval check: function(string, array): bool */
    private $approvalCallback = null;

    /** @var array<string> Tool names requiring explicit approval before execution */
    private array $highRiskTools = [];

    /** @var array<string> Authorized functional scopes/purposes for this agent */
    private array $authorizedScopes = [];

    /** @var array<string> Tool name patterns mapped to required scopes */
    private array $toolScopeMap = [];

    public function __construct(?array $blacklistPatterns = null)
    {
        $this->blacklistPatterns = $blacklistPatterns ?? [
            // Command injection / piping shell syntax
            '/[|;]/i',                       // Semi-colons and single pipes
            '/&&|\|\|/',                     // AND/OR operators
            '/`|\$\(/',                      // Command substitution
            // Dangerous commands
            '/\b(rm\s+-rf|rm\s+-r|sudo|mkfs|chmod\s+-R\s+777)\b/i',
            // File redirection
            '/>|<|\b(sh\b|bash\b|zsh\b)\s+-c\b/i',
        ];
    }

    /**
     * Register a callback for human-in-the-loop approval of high-risk operations.
     *
     * Signature: function(string $toolName, array $arguments): bool
     */
    public function setApprovalCallback(callable $callback): self
    {
        $this->approvalCallback = $callback;

        return $this;
    }

    /**
     * Set the list of tool names that require approval before execution.
     *
     * @param  array<string>  $tools
     */
    public function setHighRiskTools(array $tools): self
    {
        $this->highRiskTools = $tools;

        return $this;
    }

    /**
     * Define authorized functional scopes/purposes for this agent instance.
     *
     * Example: ['sizing', 'read-only', 'analytics']
     *
     * @param  array<string>  $scopes
     */
    public function setAuthorizedScopes(array $scopes): self
    {
        $this->authorizedScopes = $scopes;

        return $this;
    }

    /**
     * Map tool name patterns to required authorization scopes.
     *
     * Example: ['delete_*' => ['admin', 'write'], 'modify_*' => ['write']]
     *
     * @param  array<string, array<string>>  $map
     */
    public function setToolScopeMap(array $map): self
    {
        $this->toolScopeMap = $map;

        return $this;
    }

    /**
     * Check if a tool call is safe to execute.
     *
     * Validation layers (in order):
     * 1. Pattern-based safety scanning
     * 2. Purpose-based scope controls
     * 3. High-risk tool approvals
     *
     * Returns true if safe, or a descriptive error string if blocked.
     */
    public function validate(string $toolName, array $arguments): true|string
    {
        // Layer 1: Pattern-based safety scanning
        $patternResult = $this->validatePatterns($toolName, $arguments);
        if ($patternResult !== true) {
            return $patternResult;
        }

        // Layer 2: Purpose-based scope controls
        $scopeResult = $this->validateScope($toolName);
        if ($scopeResult !== true) {
            return $scopeResult;
        }

        // Layer 3: High-risk tool approvals
        $approvalResult = $this->validateApproval($toolName, $arguments);
        if ($approvalResult !== true) {
            return $approvalResult;
        }

        return true;
    }

    /**
     * Pattern-based safety validation.
     */
    private function validatePatterns(string $toolName, array $arguments): true|string
    {
        $argString = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($this->blacklistPatterns as $pattern) {
            if (preg_match($pattern, $argString)) {
                return "Safety Violation: Arguments match blocked pattern '{$pattern}'. Execution blocked.";
            }

            if (preg_match($pattern, $toolName)) {
                return "Safety Violation: Tool name matches blocked pattern '{$pattern}'. Execution blocked.";
            }
        }

        return true;
    }

    /**
     * Purpose-based scope validation.
     */
    private function validateScope(string $toolName): true|string
    {
        if (empty($this->authorizedScopes) || empty($this->toolScopeMap)) {
            return true;
        }

        foreach ($this->toolScopeMap as $pattern => $requiredScopes) {
            if (fnmatch($pattern, $toolName)) {
                $hasRequiredScope = false;
                foreach ($requiredScopes as $required) {
                    if (in_array($required, $this->authorizedScopes, true)) {
                        $hasRequiredScope = true;

                        break;
                    }
                }

                if (! $hasRequiredScope) {
                    return "Scope Violation: Tool '{$toolName}' requires one of scopes: [".implode(', ', $requiredScopes).']. '.
                        'Agent authorized scopes: ['.implode(', ', $this->authorizedScopes).']. Execution blocked.';
                }
            }
        }

        return true;
    }

    /**
     * High-risk tool approval validation.
     */
    private function validateApproval(string $toolName, array $arguments): true|string
    {
        if (empty($this->highRiskTools)) {
            return true;
        }

        $isHighRisk = false;
        foreach ($this->highRiskTools as $highRiskTool) {
            if (fnmatch($highRiskTool, $toolName)) {
                $isHighRisk = true;

                break;
            }
        }

        if (! $isHighRisk) {
            return true;
        }

        if ($this->approvalCallback === null) {
            return "Approval Required: Tool '{$toolName}' is classified as high-risk, but no approval callback is configured. Execution blocked.";
        }

        $approved = ($this->approvalCallback)($toolName, $arguments);

        if (! $approved) {
            return "Approval Denied: Tool '{$toolName}' execution was rejected by human-in-the-loop approval.";
        }

        return true;
    }
}
