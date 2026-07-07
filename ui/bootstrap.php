<?php

/**
 * phpkaiharness Web UI Bootstrap
 *
 * Loaded by all UI pages to set up the autoloader, database path,
 * and shared helper functions.
 */

// ── Autoloader ────────────────────────────────────────────────────────────────
$autoloader = null;
foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../vendor/autoload.php'] as $p) {
    if (file_exists($p)) {
        $autoloader = $p;
        break;
    }
}

if (! $autoloader) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("phpkaiharness: Dependencies not installed.\nRun 'composer install' inside the package directory.\n");
}

require $autoloader;

$laravelRoot = null;
$possibleRoots = [
    __DIR__.'/../../..',
    getenv('LARAVEL_PATH') ?: null,
    '/mnt/s/elasticcost',
    '/home/kais/elasticcost',
];

foreach ($possibleRoots as $root) {
    if ($root && file_exists($root.'/bootstrap/app.php')) {
        $laravelRoot = realpath($root);
        break;
    }
}

if ($laravelRoot) {
    $appAutoloader = $laravelRoot.'/vendor/autoload.php';
    if ($appAutoloader && file_exists($appAutoloader) && realpath($appAutoloader) !== realpath($autoloader)) {
        require_once $appAutoloader;
    }

    $laravelApp = require $laravelRoot.'/bootstrap/app.php';
    $laravelKernel = $laravelApp->make(Kernel::class);
    $laravelKernel->bootstrap();
}

if (! app()->bound('config')) {
    class SafeConfigRepository
    {
        protected $items = [];

        public function __construct()
        {
            $defaultConfig = file_exists(__DIR__.'/../config/harness.php') ? require __DIR__.'/../config/harness.php' : [];

            $publishedPath = null;
            $possibleConfigs = [
                __DIR__.'/../../../config/harness.php',
                '/mnt/s/elasticcost/config/harness.php',
                '/home/kais/elasticcost/config/harness.php',
            ];
            foreach ($possibleConfigs as $path) {
                if (file_exists($path)) {
                    $publishedPath = realpath($path);
                    break;
                }
            }

            $publishedConfig = $publishedPath ? require $publishedPath : [];
            $this->items['harness'] = array_merge($defaultConfig, $publishedConfig);

            // Load overrides if they exist
            $overridePath = null;
            if (function_exists('storage_path') && function_exists('app') && method_exists(app(), 'storagePath')) {
                $overridePath = storage_path('app/phpkaiharness/config_overrides.json');
            }
            if (! $overridePath || ! file_exists($overridePath)) {
                if ($publishedPath) {
                    $overridePath = dirname($publishedPath).DIRECTORY_SEPARATOR.'harness_overrides.json';
                }
            }

            if (file_exists($overridePath)) {
                $overrides = json_decode(file_get_contents($overridePath), true);
                if (is_array($overrides)) {
                    // Translate Windows drive letter paths in a Linux/WSL environment
                    if (DIRECTORY_SEPARATOR === '/') {
                        array_walk_recursive($overrides, function (&$value) {
                            if (is_string($value) && preg_match('/^[a-zA-Z]:\\\\/', $value)) {
                                $drive = strtolower($value[0]);
                                $value = '/mnt/'.$drive.str_replace('\\', '/', substr($value, 2));
                            }
                        });
                    }
                    $this->items['harness'] = array_replace_recursive($this->items['harness'], $overrides);
                }
            }
        }

        public function get($key, $default = null)
        {
            $array = $this->items;
            if (is_null($key)) {
                return $array;
            }
            foreach (explode('.', $key) as $segment) {
                if (is_array($array) && array_key_exists($segment, $array)) {
                    $array = $array[$segment];
                } else {
                    return value($default);
                }
            }

            return $array;
        }

        public function set($key, $value = null)
        {
            $keys = is_array($key) ? $key : [$key => $value];
            foreach ($keys as $k => $v) {
                $array = &$this->items;
                $segments = explode('.', $k);
                while (count($segments) > 1) {
                    $segment = array_shift($segments);
                    if (! isset($array[$segment]) || ! is_array($array[$segment])) {
                        $array[$segment] = [];
                    }
                    $array = &$array[$segment];
                }
                $array[array_shift($segments)] = $v;
            }
        }
    }
    app()->instance('config', new SafeConfigRepository);
}

use Illuminate\Contracts\Console\Kernel;
use Phpkaiharness\Monitor\MonitorReport;
use Phpkaiharness\Monitor\SqliteMonitorStore;

// ── DB path resolution ────────────────────────────────────────────────────────
$dbPath = getenv('PHPKAIHARNESS_DB') ?: (config('harness.cache.db_path') ?: SqliteMonitorStore::defaultDbPath());

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Return a MonitorReport connected to the monitor database.
 * Returns null if no DB file exists yet.
 */
function getReport(string $dbPath): ?MonitorReport
{
    if (! file_exists($dbPath)) {
        return null;
    }

    return new MonitorReport($dbPath);
}

/**
 * Format milliseconds into a human-readable string.
 */
function fmtMs(int $ms): string
{
    if ($ms >= 60_000) {
        return round($ms / 60_000, 1).'m';
    }
    if ($ms >= 1_000) {
        return round($ms / 1_000, 2).'s';
    }

    return $ms.'ms';
}

/**
 * Return a CSS class for a routing method badge.
 */
function methodBadgeClass(string $method): string
{
    return match (true) {
        str_contains($method, 'fast-path') => 'badge-green',
        str_contains($method, 'cache') => 'badge-green',
        str_contains($method, 'action') => 'badge-yellow',
        str_contains($method, 'cli') => 'badge-blue',
        str_contains($method, 'chat') => 'badge-cyan',
        default => 'badge-purple',
    };
}

/**
 * Safely JSON-encode a value for HTML output.
 */
function jsonPretty(mixed $v): string
{
    if (is_string($v)) {
        $decoded = json_decode($v, true);

        return $decoded !== null
            ? htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES)
            : htmlspecialchars($v, ENT_QUOTES);
    }

    return htmlspecialchars(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
}
