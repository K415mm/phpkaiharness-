<?php

namespace Phpkaiharness\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use PDO;
use Phpkaiharness\Session\SessionManager;

class SessionManagerTest extends PhpkaiharnessTestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpkaiharness_sessions_'.uniqid();
        $fs = new Filesystem;
        app()->instance('files', $fs);
        File::setFacadeApplication(app());
        app('config')->set('harness.session_isolation.enabled', true);
        app('config')->set('harness.session_isolation.base_path', $this->basePath);
        app('config')->set('harness.quantum_harness.db_path', sys_get_temp_dir().DIRECTORY_SEPARATOR.'global_quantum_'.uniqid().'.sqlite');
        app('config')->set('database.connections.agent_memory_sqlite.database', ':memory:');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath)) {
            (new Filesystem)->deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    public function test_ensure_session_creates_monitor_quantum_and_context_assets(): void
    {
        $manager = new SessionManager;
        $manager->ensureSession('session-alpha');

        $monitorPath = $manager->getMonitorDbPath('session-alpha');
        $quantumPath = $manager->getQuantumDbPath('session-alpha');
        $contextPath = $manager->getContextPath('session-alpha');

        $this->assertFileExists($monitorPath);
        $this->assertFileExists($quantumPath);
        $this->assertFileExists($contextPath);
        $this->assertJson((string) file_get_contents($contextPath));

        $pdo = new PDO('sqlite:'.$quantumPath);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('memory_nodes', $tables);
        $this->assertContains('memory_vectors', $tables);
    }

    public function test_activate_session_updates_runtime_paths_to_session_scoped_files(): void
    {
        $manager = new SessionManager;
        $manager->activateSession('session-beta');

        $expectedMonitorPath = $manager->getMonitorDbPath('session-beta');
        $expectedQuantumPath = $manager->getQuantumDbPath('session-beta');

        $this->assertSame($expectedMonitorPath, config('harness.cache.db_path'));
        $this->assertSame($expectedQuantumPath, config('harness.quantum_harness.db_path'));
        $this->assertSame($expectedQuantumPath, config('database.connections.agent_memory_sqlite.database'));
    }
}
