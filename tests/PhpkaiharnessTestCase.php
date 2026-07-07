<?php

namespace Phpkaiharness\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

abstract class PhpkaiharnessTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('app')) {
            eval('function app($abstract = null) {
                if (is_null($abstract)) {
                    return \Illuminate\Container\Container::getInstance();
                }
                return \Illuminate\Container\Container::getInstance()->make($abstract);
            }');
        }

        $container = new class extends Container
        {
            public function version(): string
            {
                return '13.x-mock';
            }

            public function storagePath(string $path = ''): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage'.($path ? DIRECTORY_SEPARATOR.$path : '');
            }
        };
        Container::setInstance($container);

        $config = new Repository([
            'harness' => [
                'default' => [
                    'provider' => 'ollama',
                    'model' => 'gemma',
                    'max_iterations' => 10,
                ],
                'cache' => [
                    'enabled' => false,
                    'db_path' => ':memory:',
                    'threshold' => 0.88,
                    'eligibility' => [
                        'reject_patterns' => ['⚠️', 'cURL error', 'LLM execution error', 'iteration limit'],
                        'reject_empty' => true,
                        'reject_min_length' => 20,
                    ],
                ],
                'pii_masking' => [
                    'enabled' => false,
                ],
                'rate_limiting' => [
                    'enabled' => false,
                ],
                'guardrails' => [
                    'enabled' => false,
                    'high_risk_tools' => [],
                    'authorized_scopes' => [],
                    'tool_scope_map' => [],
                ],
                'compression' => [
                    'enabled' => false,
                    'line_threshold' => 150,
                ],
                'bootstrap' => [
                    'enabled' => false,
                ],
                'budget' => [
                    'enabled' => false,
                    'max_tokens' => 2000,
                ],
                'failover' => [
                    'enabled' => false,
                    'clients' => [],
                ],
                'ontology' => [
                    'model_class' => 'App\\Models\\ClientAsset',
                    'similarity_threshold' => 0.30,
                ],
                'compaction' => [
                    'strategy' => 'sliding_window',
                    'max_turns' => 6,
                    'max_tokens_threshold' => 4000,
                    'compression' => [
                        'enabled' => false,
                        'line_threshold' => 150,
                    ],
                ],
                'cognitive_memory' => [
                    'enabled' => true,
                    'extraction_mode' => 'sync',
                    'quality_filter' => [
                        'min_length' => 15,
                        'reject_patterns' => ['maybe', 'might', 'possibly', 'i think', 'not sure', 'unclear', 'todo', 'tbd', 'fixme', 'hack', 'placeholder', 'lorem ipsum', 'test data', 'dummy', 'sample', 'example', 'placeholder text'],
                        'reject_markdown_only' => true,
                    ],
                    'dedup' => [
                        'enabled' => true,
                        'similarity_threshold' => 0.85,
                    ],
                ],
                'feature_graph' => [
                    'nodes' => [
                        'draft_verification' => ['enabled' => false],
                        'environment_bootstrap' => ['enabled' => false],
                        'context_compression' => ['enabled' => false],
                        'model_optimizer' => ['enabled' => false],
                        'ontology_injection' => ['enabled' => false],
                        'semantic_cache' => ['enabled' => false],
                        'context_compactor' => ['enabled' => false],
                        'guardrails' => ['enabled' => false],
                        'cognitive_memory' => ['enabled' => true],
                        'quantum_harness' => ['enabled' => false],
                    ],
                ],
            ],
            'ai' => [
                'default_for_embeddings' => 'ollama',
            ],
        ]);
        $container->instance('config', $config);
    }
}
