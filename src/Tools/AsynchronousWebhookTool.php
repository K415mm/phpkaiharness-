<?php

namespace Phpkaiharness\Tools;

use GuzzleHttp\Client;
use Phpkaiharness\Contracts\ToolInterface;

/**
 * Palantir-style asynchronous webhook tool connector.
 * Fires a webhook request containing a callback URL to receive execution state changes.
 */
class AsynchronousWebhookTool implements ToolInterface
{
    protected string $name;

    protected string $description;

    protected string $targetUrl;

    public function __construct(string $name = 'async_webhook', string $description = 'Dispatches asynchronous webhook task', string $targetUrl = '')
    {
        $this->name = $name;
        $this->description = $description;
        $this->targetUrl = $targetUrl;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payload' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs to send to the external webhook.',
                ],
                'task_name' => [
                    'type' => 'string',
                    'description' => 'Descriptive name for the asynchronous task.',
                ],
            ],
            'required' => ['payload', 'task_name'],
        ];
    }

    public function execute(array $args): string
    {
        $payload = $args['payload'] ?? [];
        $taskName = $args['task_name'] ?? 'default_task';
        $jobId = 'job_'.bin2hex(random_bytes(6));

        // Generate callback URL pointing to the harness API Callback route
        $callbackUrl = '';
        if (function_exists('route')) {
            try {
                // If named route exists, resolve it
                $callbackUrl = route('harness.api', ['action' => 'webhook_callback']);
            } catch (\Throwable $e) {
                $callbackUrl = '/harness/api?action=webhook_callback';
            }
        } else {
            $callbackUrl = '/harness/api?action=webhook_callback';
        }

        $client = new Client([
            'timeout' => 5.0,
        ]);

        $url = $this->targetUrl ?: 'http://localhost:9000/webhook-receiver';

        try {
            // Dispatch async POST request carrying the payload and callback URL
            $client->post($url, [
                'json' => [
                    'job_id' => $jobId,
                    'task_name' => $taskName,
                    'payload' => $payload,
                    'callback_url' => $callbackUrl,
                ],
            ]);

            return json_encode([
                'status' => 'pending',
                'job_id' => $jobId,
                'message' => "Asynchronous task '{$taskName}' dispatched to external system. Loop paused waiting for callback.",
            ]);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'failed',
                'error' => 'Failed to dispatch webhook: '.$e->getMessage(),
            ]);
        }
    }
}
