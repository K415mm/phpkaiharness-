<?php

namespace Phpkaiharness\Llm;

use Phpkaiharness\Contracts\LlmClientInterface;

/**
 * Builder for assembling the LLM client decorator pipeline.
 *
 * Replaces the imperative decorator assembly that was inline in
 * AgentLoop::decorateLlmClient(). Makes the decoration order explicit,
 * config-driven skips testable, and ensures failover fallback clients
 * are also decorated (previously they were raw, undecorated instances).
 *
 * Decoration order (outer → inner):
 *   Failover → ThinkingBudget → PiiMasking → RateLimit → base client
 *
 * The outermost wrapper is what AgentLoop calls ->chat() on first.
 * Each layer delegates to its innerClient after doing its own work.
 */
class LlmClientPipelineBuilder
{
    private bool $rateLimiting = true;

    private int $requestsPerMinute = 60;

    private int $cooldownMs = 0;

    private bool $piiMasking = true;

    /** @var array<string, string> */
    private array $piiPatterns = [];

    private bool $thinkingBudget = true;

    private int $maxTokensBudget = 30000;

    private bool $failover = true;

    /** @var array<int, array{provider: string, model: string}> */
    private array $failoverClients = [];

    private bool $autoDecorate = true;

    public function __construct(private readonly LlmClientFactory $factory = new LlmClientFactory) {}

    /**
     * Configure the builder from the harness config array.
     *
     * @param  array  $config  The 'harness' config subtree.
     */
    public function fromConfig(array $config): self
    {
        $this->autoDecorate = $config['auto_decorate'] ?? true;

        $this->rateLimiting = $config['rate_limiting']['enabled'] ?? true;
        $this->requestsPerMinute = (int) ($config['rate_limiting']['requests_per_minute'] ?? 60);
        $this->cooldownMs = (int) ($config['rate_limiting']['cooldown_ms'] ?? 0);

        $this->piiMasking = $config['pii_masking']['enabled'] ?? ($config['pii']['enabled'] ?? true);
        $this->piiPatterns = (array) ($config['pii_masking']['patterns'] ?? ($config['pii']['patterns'] ?? []));

        $this->thinkingBudget = $config['budget']['enabled'] ?? ($config['thinking_budget']['enabled'] ?? true);
        $this->maxTokensBudget = (int) ($config['budget']['max_tokens'] ?? ($config['thinking_budget']['max_tokens'] ?? ($config['thinking_budget']['max_thinking_tokens'] ?? 30000)));

        $this->failover = $config['failover']['enabled'] ?? ($config['llm_failover']['enabled'] ?? true);
        $this->failoverClients = (array) ($config['failover']['clients'] ?? ($config['llm_failover']['clients'] ?? []));

        return $this;
    }

    public function withRateLimiting(bool $enabled = true, int $rpm = 60, int $cooldownMs = 0): self
    {
        $this->rateLimiting = $enabled;
        $this->requestsPerMinute = $rpm;
        $this->cooldownMs = $cooldownMs;

        return $this;
    }

    public function withPiiMasking(bool $enabled = true, array $patterns = []): self
    {
        $this->piiMasking = $enabled;
        $this->piiPatterns = $patterns;

        return $this;
    }

    public function withThinkingBudget(bool $enabled = true, int $maxTokens = 30000): self
    {
        $this->thinkingBudget = $enabled;
        $this->maxTokensBudget = $maxTokens;

        return $this;
    }

    public function withFailover(bool $enabled = true, array $clients = []): self
    {
        $this->failover = $enabled;
        $this->failoverClients = $clients;

        return $this;
    }

    /**
     * Build the decorated LLM client pipeline around the given base client.
     *
     * If auto_decorate is disabled, the base client is returned as-is.
     * Failover fallback clients are individually decorated with the same
     * rate-limit / PII / budget layers before being added to the failover chain.
     */
    public function build(LlmClientInterface $baseClient): LlmClientInterface
    {
        if (! $this->autoDecorate) {
            return $baseClient;
        }

        $client = $this->decorateSingle($baseClient);

        if ($this->failover && ! empty($this->failoverClients)) {
            $instances = [$client];
            foreach ($this->failoverClients as $fo) {
                $provider = $fo['provider'] ?? '';
                $model = $fo['model'] ?? '';
                if (! empty($provider) && ! empty($model)) {
                    $fallbackBase = $this->factory->make($provider, $model);
                    $instances[] = $this->decorateSingle($fallbackBase);
                }
            }
            if (count($instances) > 1) {
                $client = new FailoverLlmClient(clients: $instances);
            }
        }

        return $client;
    }

    /**
     * Apply the rate-limit → PII → budget decorator chain to a single client.
     */
    private function decorateSingle(LlmClientInterface $client): LlmClientInterface
    {
        if ($this->rateLimiting) {
            $client = new RateLimitedLlmClient(
                innerClient: $client,
                requestsPerMinute: $this->requestsPerMinute,
                cooldownMs: $this->cooldownMs
            );
        }

        if ($this->piiMasking) {
            $client = new PiiMaskingLlmClient(
                innerClient: $client,
                patterns: $this->piiPatterns
            );
        }

        if ($this->thinkingBudget) {
            $client = new ThinkingBudgetLlmClient(
                innerClient: $client,
                maxTokensBudget: $this->maxTokensBudget
            );
        }

        return $client;
    }
}
