<?php

namespace Phpkaiharness\Tests;

use Phpkaiharness\Optimize\ContextCompactor;
use Phpkaiharness\Optimize\QuantumInferenceEngine;

class QuantumAndCompactionTest extends PhpkaiharnessTestCase
{
    public function test_estimate_tokens_returns_positive_for_non_empty_history(): void
    {
        $compactor = new ContextCompactor('sliding_window', 6, 4000);

        $history = [
            ['role' => 'user', 'content' => 'Hello, this is a test message'],
            ['role' => 'assistant', 'content' => 'I received your message and will process it.'],
        ];

        $tokens = $compactor->estimateTokens($history);

        $this->assertGreaterThan(0, $tokens);
    }

    public function test_estimate_tokens_returns_zero_for_empty_history(): void
    {
        $compactor = new ContextCompactor;

        $this->assertSame(0, $compactor->estimateTokens([]));
    }

    public function test_compact_does_not_trigger_when_under_limits(): void
    {
        $compactor = new ContextCompactor('sliding_window', 10, 10000);

        $history = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ];

        $compactor->compact($history, 'system', 'test-model');

        $this->assertFalse($compactor->getLastTelemetry()['compacted']);
        $this->assertSame(2, $compactor->getLastTelemetry()['turns_before']);
        $this->assertSame(2, $compactor->getLastTelemetry()['turns_after']);
    }

    public function test_compact_triggers_on_token_threshold(): void
    {
        $compactor = new ContextCompactor('sliding_window', 100, 10);

        // Create history with enough content to exceed 10 tokens (~40 chars)
        $history = [
            ['role' => 'user', 'content' => 'This is a very long user message that exceeds the token threshold'],
            ['role' => 'assistant', 'content' => 'This is a very long assistant response that also exceeds the threshold'],
            ['role' => 'user', 'content' => 'Another long message to ensure we are over the limit'],
            ['role' => 'assistant', 'content' => 'Yet another long response from the assistant'],
        ];

        $compactor->compact($history, 'system', 'test-model');

        $telemetry = $compactor->getLastTelemetry();
        $this->assertTrue($telemetry['compacted']);
        $this->assertLessThan($telemetry['tokens_before'], $telemetry['tokens_after']);
    }

    public function test_compact_telemetry_tracks_turns_before_and_after(): void
    {
        $compactor = new ContextCompactor('sliding_window', 3, 10000);

        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $history[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "Message {$i}"];
        }

        $compactor->compact($history, 'system', 'test-model');

        $telemetry = $compactor->getLastTelemetry();
        $this->assertTrue($telemetry['compacted']);
        $this->assertSame(10, $telemetry['turns_before']);
        $this->assertLessThan(10, $telemetry['turns_after']);
    }

    public function test_quantum_retrieve_with_telemetry_returns_empty_for_no_nodes(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_quantum_'.uniqid().'.db';
        $engine = new QuantumInferenceEngine($dbPath);

        $result = $engine->retrieveWithTelemetry('test query', 0.0);

        $this->assertSame('', $result['context']);
        $this->assertSame(0, $result['node_count']);
        $this->assertSame(0, $result['anchors_found']);
        $this->assertGreaterThanOrEqual(0, $result['retrieval_ms']);

        @unlink($dbPath);
    }

    public function test_quantum_retrieve_with_telemetry_returns_data_after_storing_nodes(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_quantum_'.uniqid().'.db';
        $engine = new QuantumInferenceEngine($dbPath);

        // Store a node with a pseudo-embedding (no embedding provider in test)
        $engine->storeNode('test-node-1', 'semantic', 'The client database was configured with SSL', 0.0);

        $result = $engine->retrieveWithTelemetry('client database configuration', 0.0);

        $this->assertSame(1, $result['node_count']);
        $this->assertGreaterThanOrEqual(0, $result['retrieval_ms']);

        @unlink($dbPath);
    }

    public function test_quantum_prune_old_nodes_returns_zero_when_under_limit(): void
    {
        $dbPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_quantum_'.uniqid().'.db';
        $engine = new QuantumInferenceEngine($dbPath);

        $engine->storeNode('node-1', 'semantic', 'Test content', 0.0);

        $pruned = $engine->pruneOldNodes(10000);

        $this->assertSame(0, $pruned);

        @unlink($dbPath);
    }
}
