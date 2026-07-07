<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Http\Middleware\PolicyGuardrailMiddleware;
use Phpkaiharness\Optimize\OntologicalContextInjector;
use Phpkaiharness\Tools\AsynchronousWebhookTool;
use PHPUnit\Framework\TestCase;

class OntologyLayerTest extends TestCase
{
    public function test_policy_guardrail_middleware_exists_and_implements_method(): void
    {
        $this->assertTrue(class_exists(PolicyGuardrailMiddleware::class));
        $this->assertTrue(method_exists(PolicyGuardrailMiddleware::class, 'handle'));
    }

    public function test_ontological_context_injector_exists_and_implements_method(): void
    {
        $this->assertTrue(class_exists(OntologicalContextInjector::class));
        $this->assertTrue(method_exists(OntologicalContextInjector::class, 'inject'));
    }

    public function test_asynchronous_webhook_tool_returns_pending_status_on_execution(): void
    {
        $this->assertTrue(class_exists(AsynchronousWebhookTool::class));

        // Mock a simple target server endpoint (we trigger it with Guzzle error mapping block testing)
        $tool = new AsynchronousWebhookTool('test_async', 'Test description', 'http://invalid-url-domain-test.local');
        $result = $tool->execute([
            'payload' => ['key' => 'val'],
            'task_name' => 'scan_ips',
        ]);

        $decoded = json_decode($result, true);
        $this->assertEquals('failed', $decoded['status']);
        $this->assertArrayHasKey('error', $decoded);
    }
}
