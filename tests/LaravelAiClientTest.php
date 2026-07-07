<?php

namespace Phpkaiharness\Tests;

use App\Services\AiConfigHelper;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Phpkaiharness\Llm\LaravelAiClient;
use Phpkaiharness\Llm\RawSchemaLaravelTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class LaravelAiClientTest extends PhpkaiharnessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Define global app helper if not exists (running standalone in phpunit)
        if (! function_exists('app')) {
            eval('function app($abstract = null) {
                if (is_null($abstract)) {
                    return \Illuminate\Container\Container::getInstance();
                }
                return \Illuminate\Container\Container::getInstance()->make($abstract);
            }');
        }

        // Reset container instance and bind config
        $container = new Container;
        $container->instance('config', new Repository([
            'harness' => [
                'default' => [
                    'provider' => 'ollama',
                    'model' => 'llama3.2',
                ],
            ],
        ]));
        Container::setInstance($container);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_laravel_ai_client_delegates_to_gateway(): void
    {
        $mockUsage = new Usage(10, 20);
        $mockMeta = new Meta('ollama', 'gemma');
        $mockResponse = new TextResponse(
            text: 'Hello from Laravel AI SDK mock!',
            usage: $mockUsage,
            meta: $mockMeta
        );

        $gatewayMock = $this->createMock(TextGateway::class);
        $gatewayMock->expects($this->once())
            ->method('generateText')
            ->willReturn($mockResponse);

        $providerMock = $this->createMock(TextProvider::class);
        $providerMock->method('textGateway')
            ->willReturn($gatewayMock);

        $aiManagerMock = $this->createMock(AiManager::class);
        $aiManagerMock->method('textProvider')
            ->with('ollama')
            ->willReturn($providerMock);

        // Bind the mock to the container
        Container::getInstance()->instance(AiManager::class, $aiManagerMock);

        $client = new LaravelAiClient('ollama', 'gemma');
        $result = $client->chat(
            systemPrompt: 'You are a test assistant',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
            ]
        );

        $this->assertEquals('Hello from Laravel AI SDK mock!', $result['content']);
        $this->assertEmpty($result['tool_calls']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_raw_schema_laravel_tool_serializes_correctly(): void
    {
        $rawToolDefinition = [
            'name' => 'get_weather',
            'description' => 'Get the current weather',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city name',
                    ],
                ],
                'required' => ['location'],
            ],
        ];

        $typeMock = $this->createMock(Type::class);
        $typeMock->method('description')->willReturnSelf();
        $typeMock->method('required')->willReturnSelf();

        $schemaMock = $this->createMock(JsonSchema::class);
        $schemaMock->method('string')->willReturn($typeMock);

        $tool = new RawSchemaLaravelTool($rawToolDefinition);
        $this->assertEquals('get_weather', $tool->name());
        $this->assertEquals('Get the current weather', $tool->description());

        $schema = $tool->schema($schemaMock);
        $this->assertArrayHasKey('location', $schema);
        $this->assertSame($typeMock, $schema['location']);
    }

    public function test_laravel_ai_client_resolves_config_from_host_helper(): void
    {
        if (! class_exists('App\Services\AiConfigHelper')) {
            eval('namespace App\Services; class AiConfigHelper {
                public static $mockConfig = ["provider" => "gemini", "model" => "gemini-1.5-pro"];
                public static function configure() {
                    return self::$mockConfig;
                }
            }');
        } else {
            // If class already exists, try to override if it has mockConfig property, or just skip
            try {
                AiConfigHelper::$mockConfig = ['provider' => 'gemini', 'model' => 'gemini-1.5-pro'];
            } catch (\Throwable $e) {
                $this->markTestSkipped('Real App\Services\AiConfigHelper already defined and cannot be mocked.');

                return;
            }
        }

        $client = new LaravelAiClient('ollama', 'llama3.2');
        $this->assertEquals('gemini-1.5-pro', $client->getResolvedModel());
    }

    public function test_laravel_ai_client_resolves_config_from_ai_default(): void
    {
        // Temporarily clear environment overrides
        $oldProvider = getenv('PHPKAIHARNESS_PROVIDER');
        putenv('PHPKAIHARNESS_PROVIDER');

        // Set App\Services\AiConfigHelper mock to empty/fail
        if (class_exists('App\Services\AiConfigHelper')) {
            try {
                AiConfigHelper::$mockConfig = [];
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Set ai.default config
        if (function_exists('config')) {
            config(['ai.default' => 'openai']);
        }

        $client = new LaravelAiClient('ollama', 'llama3.2');
        $this->assertEquals('gpt-4o', $client->getResolvedModel());

        // Restore environment
        if ($oldProvider !== false) {
            putenv("PHPKAIHARNESS_PROVIDER={$oldProvider}");
        } else {
            putenv('PHPKAIHARNESS_PROVIDER');
        }
    }
}
