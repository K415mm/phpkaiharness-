<?php

namespace Phpkaiharness\Llm;

use Exception;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FailoverLlmClient implements LlmClientInterface
{
    protected LoggerInterface $logger;

    /**
     * @param  array<LlmClientInterface>  $clients  Ordered list of LLM client instances.
     *                                              The first client is the primary; subsequent entries are fallbacks.
     */
    public function __construct(
        protected array $clients,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Attempt each client in order, falling back to the next on any exception.
     *
     * @return array{content: ?string, tool_calls: array<mixed>}
     *
     * @throws Exception If all clients fail.
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
        $lastException = null;

        foreach ($this->clients as $index => $client) {
            try {
                $this->logger->debug('FailoverLlmClient: trying client #'.$index.' ('.get_class($client).')');

                return $client->chat(
                    $systemPrompt,
                    $messages,
                    $tools,
                    $model,
                    $sessionId,
                    $collector,
                    $onChunk
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    'FailoverLlmClient: client #'.$index.' ('.get_class($client).') failed: '.$e->getMessage().'. Trying next.'
                );
                if ($collector && $sessionId) {
                    $collector->recordEvent(
                        $sessionId,
                        'failover',
                        'FailoverLlmClient',
                        ['failed_client' => get_class($client), 'error' => $e->getMessage()],
                        'Client #'.$index.' failed, falling back to client #'.($index + 1)
                    );
                }
                $lastException = $e;
            }
        }

        throw new Exception(
            'FailoverLlmClient: all '.count($this->clients).' client(s) failed. Last error: '.$lastException?->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Delegate model resolution to the primary (first) client.
     */
    public function getResolvedModel(): string
    {
        $primary = $this->clients[0] ?? null;

        return $primary instanceof LlmClientInterface ? $primary->getResolvedModel() : '';
    }
}
