<?php

namespace Phpkaiharness\Tools;

use Exception;
use GuzzleHttp\Client;
use Phpkaiharness\Contracts\ToolInterface;

class HttpServiceTool implements ToolInterface
{
    protected string $name;

    protected string $description;

    protected array $schema;

    protected string $endpoint;

    protected ?string $secretToken;

    protected Client $httpClient;

    public function __construct(
        string $name,
        string $description,
        array $schema,
        string $endpoint,
        ?string $secretToken = null
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->schema = $schema;
        $this->endpoint = $endpoint;
        $this->secretToken = $secretToken;

        $headers = ['Content-Type' => 'application/json'];
        if (! empty($this->secretToken)) {
            $headers['Authorization'] = 'Bearer '.$this->secretToken;
        }

        $this->httpClient = new Client([
            'timeout' => 60.0,
            'headers' => $headers,
        ]);
    }

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Get the schema.
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Execute the tool by forwarding arguments to the microservice endpoint.
     */
    public function execute(array $args): string
    {
        try {
            $response = $this->httpClient->post($this->endpoint, [
                'json' => [
                    'tool' => $this->name,
                    'arguments' => $args,
                ],
            ]);

            return $response->getBody()->getContents();
        } catch (Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => "Failed to reach microservice tool at {$this->endpoint}: ".$e->getMessage(),
            ]);
        }
    }
}
