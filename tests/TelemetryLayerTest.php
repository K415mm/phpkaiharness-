<?php

namespace Phpkaiharness\Tests;

use Illuminate\Foundation\Application;
use Phpkaiharness\Http\Controllers\HarnessTelemetryController;
use Phpkaiharness\Monitor\MonitorReport;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\PhpkaiharnessServiceProvider;
use PHPUnit\Framework\TestCase;

class TelemetryLayerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Configuration File Tests
    // -------------------------------------------------------------------------

    public function test_config_file_exists_and_returns_array(): void
    {
        $configPath = __DIR__.'/../config/harness.php';

        $this->assertFileExists($configPath);

        $config = require $configPath;

        $this->assertIsArray($config);
        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('failover', $config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertArrayHasKey('pii_masking', $config);
        $this->assertArrayHasKey('rate_limiting', $config);
        $this->assertArrayHasKey('guardrails', $config);
        $this->assertArrayHasKey('compaction', $config);
        $this->assertArrayHasKey('telemetry', $config);
    }

    public function test_config_has_required_default_keys(): void
    {
        $config = require __DIR__.'/../config/harness.php';

        $this->assertArrayHasKey('provider', $config['default']);
        $this->assertArrayHasKey('model', $config['default']);
        $this->assertArrayHasKey('max_iterations', $config['default']);
    }

    public function test_config_pii_masking_has_patterns(): void
    {
        $config = require __DIR__.'/../config/harness.php';

        $this->assertTrue($config['pii_masking']['enabled']);
        $this->assertArrayHasKey('patterns', $config['pii_masking']);
        $this->assertArrayHasKey('EMAIL', $config['pii_masking']['patterns']);
        $this->assertArrayHasKey('IP', $config['pii_masking']['patterns']);
        $this->assertArrayHasKey('CREDIT_CARD', $config['pii_masking']['patterns']);
        $this->assertArrayHasKey('API_KEY', $config['pii_masking']['patterns']);
    }

    public function test_config_guardrails_has_high_risk_tools(): void
    {
        $config = require __DIR__.'/../config/harness.php';

        $this->assertTrue($config['guardrails']['enabled']);
        $this->assertIsArray($config['guardrails']['high_risk_tools']);
        $this->assertNotEmpty($config['guardrails']['high_risk_tools']);
    }

    public function test_config_telemetry_has_route_settings(): void
    {
        $config = require __DIR__.'/../config/harness.php';

        $this->assertTrue($config['telemetry']['enabled']);
        $this->assertEquals('harness', $config['telemetry']['route_prefix']);
        $this->assertIsArray($config['telemetry']['middleware']);
    }

    // -------------------------------------------------------------------------
    // Service Provider Tests
    // -------------------------------------------------------------------------

    public function test_service_provider_class_exists(): void
    {
        $this->assertTrue(class_exists('Phpkaiharness\PhpkaiharnessServiceProvider'));
    }

    public function test_service_provider_has_register_method(): void
    {
        $provider = new PhpkaiharnessServiceProvider(new Application);

        $this->assertTrue(method_exists($provider, 'register'));
    }

    public function test_service_provider_has_boot_method(): void
    {
        $provider = new PhpkaiharnessServiceProvider(new Application);

        $this->assertTrue(method_exists($provider, 'boot'));
    }

    // -------------------------------------------------------------------------
    // Routes File Tests
    // -------------------------------------------------------------------------

    public function test_routes_file_exists(): void
    {
        $routesPath = __DIR__.'/../routes/web.php';

        $this->assertFileExists($routesPath);
    }

    public function test_routes_file_contains_dashboard_route(): void
    {
        $routesContent = file_get_contents(__DIR__.'/../routes/web.php');

        $this->assertStringContainsString('dashboard', $routesContent);
        $this->assertStringContainsString('HarnessTelemetryController', $routesContent);
    }

    public function test_routes_file_contains_api_routes(): void
    {
        $routesContent = file_get_contents(__DIR__.'/../routes/web.php');

        $this->assertStringContainsString("'stats'", $routesContent);
        $this->assertStringContainsString("'sessions'", $routesContent);
        $this->assertStringContainsString("prefix('api')", $routesContent);
    }

    // -------------------------------------------------------------------------
    // View Tests
    // -------------------------------------------------------------------------

    public function test_dashboard_view_file_exists(): void
    {
        $viewPath = __DIR__.'/../resources/views/dashboard.blade.php';

        $this->assertFileExists($viewPath);
    }

    public function test_dashboard_view_contains_required_sections(): void
    {
        $viewContent = file_get_contents(__DIR__.'/../resources/views/dashboard.blade.php');

        $this->assertStringContainsString('stats', $viewContent);
        $this->assertStringContainsString('sessions', $viewContent);
        $this->assertStringContainsString('phpkaiharness', $viewContent);
        $this->assertStringContainsString('run-agent', $viewContent);
    }

    public function test_dashboard_view_contains_stats_grid(): void
    {
        $viewContent = file_get_contents(__DIR__.'/../resources/views/dashboard.blade.php');

        $this->assertStringContainsString('id="stats"', $viewContent);
        $this->assertStringContainsString('card', $viewContent);
        $this->assertStringContainsString('Total Sessions', $viewContent);
        $this->assertStringContainsString('LLM Calls', $viewContent);
    }

    public function test_dashboard_view_contains_sessions_table(): void
    {
        $viewContent = file_get_contents(__DIR__.'/../resources/views/dashboard.blade.php');

        $this->assertStringContainsString('sessionTable', $viewContent);
        $this->assertStringContainsString('@foreach', $viewContent);
    }

    // -------------------------------------------------------------------------
    // Controller Tests
    // -------------------------------------------------------------------------

    public function test_telemetry_controller_class_exists(): void
    {
        $this->assertTrue(class_exists('Phpkaiharness\Http\Controllers\HarnessTelemetryController'));
    }

    public function test_telemetry_controller_has_required_methods(): void
    {
        $this->assertTrue(method_exists(HarnessTelemetryController::class, 'dashboard'));
        $this->assertTrue(method_exists(HarnessTelemetryController::class, 'stats'));
        $this->assertTrue(method_exists(HarnessTelemetryController::class, 'sessions'));
        $this->assertTrue(method_exists(HarnessTelemetryController::class, 'show'));
        $this->assertTrue(method_exists(HarnessTelemetryController::class, 'api'));
    }

    // -------------------------------------------------------------------------
    // Directory Structure Tests
    // -------------------------------------------------------------------------

    public function test_config_directory_exists(): void
    {
        $this->assertDirectoryExists(__DIR__.'/../config');
    }

    public function test_routes_directory_exists(): void
    {
        $this->assertDirectoryExists(__DIR__.'/../routes');
    }

    public function test_resources_views_directory_exists(): void
    {
        $this->assertDirectoryExists(__DIR__.'/../resources/views');
    }

    public function test_http_controllers_directory_exists(): void
    {
        $this->assertDirectoryExists(__DIR__.'/../src/Http/Controllers');
    }

    // -------------------------------------------------------------------------
    // Integration Tests
    // -------------------------------------------------------------------------

    public function test_controller_uses_sqlite_monitor_report(): void
    {
        $controllerSource = file_get_contents(__DIR__.'/../src/Http/Controllers/HarnessTelemetryController.php');

        $this->assertStringContainsString('MonitorReport', $controllerSource);
        $this->assertStringNotContainsString('DB::table', $controllerSource);
    }

    public function test_controller_has_monitor_report_instance(): void
    {
        $controllerSource = file_get_contents(__DIR__.'/../src/Http/Controllers/HarnessTelemetryController.php');

        $this->assertStringContainsString('$this->report = new MonitorReport', $controllerSource);
    }

    public function test_sqlite_monitor_store_still_works_standalone(): void
    {
        $dbPath = sys_get_temp_dir().'/test_harness_'.uniqid().'.db';

        $store = new SqliteMonitorStore($dbPath);
        $store->startSession('test-session-1', 'Test prompt', 'executor-loop');
        $store->endSession('test-session-1', 'Test response', 1000, 2);

        $report = new MonitorReport($dbPath);
        $stats = $report->getStats();

        $this->assertGreaterThanOrEqual(1, $stats['total_sessions']);

        @unlink($dbPath);
    }
}
