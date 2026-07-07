<?php

namespace Phpkaiharness\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Phpkaiharness\Monitor\SqliteMonitorStore;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'harness:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and initialize phpkaiharness';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Installing phpkaiharness...');

        // 1. Publish Configuration
        $this->comment('Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'harness-config',
            '--force' => false,
        ]);

        // 2. Publish Assets
        $this->comment('Publishing assets...');
        $this->call('vendor:publish', [
            '--tag' => 'harness-assets',
            '--force' => true,
        ]);

        // 3. Ensure storage directory exists
        $this->comment('Creating storage directory...');
        $dbPath = SqliteMonitorStore::defaultDbPath();
        $dir = dirname($dbPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true, true);
            $this->info("Created directory: {$dir}");
        }

        // 4. Initialize SQLite database schema
        $this->comment('Initializing database...');
        try {
            new SqliteMonitorStore($dbPath);
            $this->info("Database initialized at: {$dbPath}");
        } catch (\Throwable $e) {
            $this->error("Failed to initialize database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('phpkaiharness installed successfully.');

        return self::SUCCESS;
    }
}
