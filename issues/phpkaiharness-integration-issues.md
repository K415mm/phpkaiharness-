# phpkaiharness Integration Issues Report (Resolved)

## Context
Integrated `kai/phpkaiharness` (dev-main, commit acc7ddf) into a production Laravel 13 CTI application.
The app uses Laravel 13, PHP 8.5, `laravel/ai` v0.8.1, and runs on Windows with Kali WSL.

All 20 reported integration issues have been resolved. See resolutions below.

---

## Critical Issues (Blocks Integration)

### 1. [RESOLVED] No Composer Auto-Discovery — Service Provider Must Be Manually Registered

**Problem:**
`composer.json` has no `extra.laravel.providers` field. Laravel's package auto-discovery doesn't register `PhpkaiharnessServiceProvider`, so the package's config merge, views, routes, and publishable assets are all inactive after `composer require`.

**Fix needed in `composer.json`:**
```json
"extra": {
    "laravel": {
        "providers": [
            "Phpkaiharness\\PhpkaiharnessServiceProvider"
        ]
    }
}
```

**Impact:** Every consumer must manually add the provider to `bootstrap/providers.php`. Most will assume the package is broken when routes return 404.

**Resolution:**
Added the `extra.laravel.providers` block to the package's [composer.json](file:///s:/elasticcost/packages/phpkaiharness/composer.json) pointing to `Phpkaiharness\PhpkaiharnessServiceProvider`. Laravel now auto-discovers and registers the package services correctly.

---

### 2. [RESOLVED] Published Config Loses All `env()` Calls — Hardcodes `false` Everywhere

**Problem:**
The source config at `vendor/kai/phpkaiharness/config/harness.php` correctly uses `env()` calls:
```php
'telemetry' => [
    'enabled' => env('PHPKAIHARNESS_TELEMETRY_ENABLED', true),
],
```

But after `php artisan vendor:publish --tag=harness-config`, the published `config/harness.php` has all `env()` calls stripped and values hardcoded to `false`:
```php
'telemetry' =>
array (
    'enabled' => false,
),
```

**Fix needed:**
The `HarnessConfigController::save()` method uses `var_export()` to write the config file:
```php
$content = "<?php\n\nreturn ".var_export($updated, true).";\n";
```
`var_export()` serializes the *resolved* config values (booleans), not the `env()` calls. After the first save, all env-based configuration is permanently lost.

**Resolution:**
Refactored configuration overrides to be persisted in a separate JSON file (`storage/app/phpkaiharness/config_overrides.json` or `harness_overrides.json`), keeping the main published config file intact with comments and `env()` calls preserved. At runtime, the service provider merges this JSON payload with the base configuration.

---

### 3. [RESOLVED] MonitorReport Crashes on Fresh Install — No Schema Initialization

**Problem:**
`HarnessTelemetryController` constructs `MonitorReport` directly with a DB path:
```php
$this->report = new MonitorReport($dbPath);
```

`MonitorReport::__construct()` opens the SQLite file with `new PDO('sqlite:'.$source)` but does **NOT** call `initSchema()`. Only `SqliteMonitorStore` calls `initSchema()` in its constructor.

On a fresh install (no agent has run yet), the DB file either doesn't exist or has no tables. The dashboard immediately throws:
```
PDOException: SQLSTATE[HY000]: General error: 1 no such table: harness_sessions
```

**Resolution:**
Updated `MonitorReport` to automatically initialize the SQLite schema via its constructor connection calls when the tables are missing.

---

### 4. [RESOLVED] No Artisan Install/Init Command

**Problem:**
There's no `php artisan harness:install` command. The user must manually run publish commands, create folders, and bootstrap databases.

**Resolution:**
Created the `harness:install` command in [InstallCommand.php](file:///s:/elasticcost/packages/phpkaiharness/src/Console/Commands/InstallCommand.php) and registered it in the service provider. The command automatically publishes package configurations and assets, initializes directories, and runs the SQLite schema setup.

---

## High Priority Issues

### 5. [RESOLVED] Package Not on Packagist — README Install Instructions Fail

**Problem:**
README says `composer require kai/phpkaiharness` but it is not on Packagist.

**Resolution:**
Updated the [README.md](file:///s:/elasticcost/packages/phpkaiharness/README.md) to document the correct VCS repository installation steps:
```bash
composer config repositories.phpkaiharness vcs "https://github.com/K415mm/phpkaiharness-.git"
composer require kai/phpkaiharness:dev-main
```

---

### 6. [RESOLVED] `laravel/ai` Version Constraint Too Restrictive — Forces Downgrade

**Problem:**
`composer.json` required `"laravel/ai": "^0.7.0"`, which forced a downgrade in hosts running `laravel/ai` v0.8.x.

**Resolution:**
Widened the constraint in `composer.json` to `"laravel/ai": "^0.7.0 || ^0.8.0"`.

---

### 7. [RESOLVED] No Container Bindings for Key Interfaces

**Problem:**
The service provider didn't bind `LlmClientInterface`, `ToolInterface` tags, `AnalyticsCollectorInterface`, or `ToolRegistry` as a singleton.

**Resolution:**
Registered appropriate container bindings and singletons inside `PhpkaiharnessServiceProvider::register()`, including `SqliteMonitorStore` bound to `AnalyticsCollectorInterface`, and `ToolRegistry` as a singleton.

---

### 8. [RESOLVED] No Laravel Facade

**Problem:**
No `Harness` facade for convenient access.

**Resolution:**
Created the `Harness` facade class and bound `'harness'` in the service provider to point to `HarnessService`.

---

## Medium Priority Issues

### 9. [RESOLVED] Config Save Destroys `env()` Calls Permanently

**Problem:**
`HarnessConfigController::save()` used `var_export()` which overwrote `config/harness.php` with serialized booleans, stripping all comments and `env()` declarations.

**Resolution:**
Resolved via JSON config overrides. The raw configuration file is never overwritten by the controller.

---

### 10. [RESOLVED] Config Key Mismatch Between README and Code

**Problem:**
README listed keys like `semantic_cache` and `rate_limiting` while code read `cache` and `rate_limits`.

**Resolution:**
Implemented fallback config key aliases in the decorators and loaders (e.g., mapping `semantic_cache` to `cache`) so both documented and internal formats work seamlessly.

---

### 11. [RESOLVED] AgentLoop Auto-Config Reads Wrong Config Keys

**Problem:**
`AgentLoop` read raw keys like `cache` and `compaction` ignoring the documented README configuration format.

**Resolution:**
Updated configuration loader paths to correctly resolve alias configuration keys.

---

### 12. [RESOLVED] `SqliteMonitorStore::defaultDbPath()` Uses Home Directory — Not Laravel-Aware

**Problem:**
`defaultDbPath()` used `getenv('HOME')` which is often missing or resolves to unexpected system systemprofile paths on Windows.

**Resolution:**
Modified `defaultDbPath()` to prioritize Laravel's `storage_path()` helper on Windows systems.

---

### 13. [RESOLVED] No Middleware for Route Protection

**Problem:**
Telemetry and config routes were publicly accessible by default under `['web']`.

**Resolution:**
Created a secure [Authorize.php](file:///s:/elasticcost/packages/phpkaiharness/src/Http/Middleware/Authorize.php) middleware. It permits local dev and testing environments by default, but checks the `viewHarness` Gate for production/staging to block unauthorized access.

---

### 14. [RESOLVED] README Mentions `/harness/playground` Route — Doesn't Exist

**Problem:**
README referenced `/harness/playground` which did not exist.

**Resolution:**
Updated README to correctly guide users to the dashboard and config endpoints.

---

### 15. [RESOLVED] `LaravelAiClient` Imports Test Dependencies in Production Code

**Problem:**
Imports for `Mockery\MockInterface` and `PHPUnit\Framework\MockObject\MockObject` were resolved at compile-time in production class files.

**Resolution:**
Removed the static test-framework imports. Converted mockup verification checks to dynamic runtime inspections using `class_exists()` / `interface_exists()`.

---

## Low Priority / Polish

### 16. [RESOLVED] No `.gitignore` Entry for Harness SQLite DB

**Problem:**
No guidance on ignoring SQLite artifacts.

**Resolution:**
Updated README to instruct developers to add `storage/app/phpkaiharness/` (or the override path) to their `.gitignore`.

---

### 17. [RESOLVED] `guzzlehttp/guzzle ^7.8` May Conflict

**Problem:**
Strict Guzzle constraint prevented integration with Guzzle 8.x.

**Resolution:**
Widened `guzzlehttp/guzzle` constraint to `^7.8 || ^8.0`.

---

### 18. [RESOLVED] No Migration Support for Existing SQLite DBs

**Problem:**
Schema changes would break older databases.

**Resolution:**
Integrated automatic connection-level schema upgrades and creation routines in `SqliteMonitorStore`.

---

### 19. [RESOLVED] `AgentLoop` Catches `\Throwable` Silently

**Problem:**
Silent catches swallowed exceptions without logs, making integration issues invisible.

**Resolution:**
Replaced empty catches with logging statements outputting warning or debug details.

---

### 20. [RESOLVED] No Type-Hinted Return on `AgentLoop::run()`

**Problem:**
Unclear signature return value when streaming is enabled.

**Resolution:**
Standardized signatures and updated docblocks to accurately document returning either a string or a callback/stream resource.

---

### 21. [RESOLVED] Package uses internal AI provider defaults instead of host application's configuration

**Problem:**
When integrated into a host Laravel application, the `phpkaiharness` package defaulted to its own internal provider ('ollama') and model ('llama3.2') configurations. It did not automatically utilize the host application's configured default AI provider or dynamically resolved AI configuration, resulting in connection or provider mismatch issues during testing.

**Resolution:**
Refactored `LaravelAiClient` to check if the `PHPKAIHARNESS_PROVIDER` environment variable is set. If not, it dynamically attempts to resolve the AI provider and model from the host application. It first checks if the host's `App\Services\AiConfigHelper` exists to query active database settings, and falls back to `config('ai.default')` and standard driver-specific default models.

**Updated (v2.1):** The default provider is now `qwen` (Qwen Cloud) instead of `ollama`. `QwenClient` implements a full hybrid credential resolution chain: constructor args > host app `global_settings` (via `GlobalSetting::getValue('qwen_api_key')`, `qwen_url`, `qwen_model`) > Laravel AI SDK config (`ai.providers.qwen.*`) > harness config (`harness.qwen_provider.*`) > environment variables. This means when the host app's System Settings → AI Provider is set to `qwen`, the harness automatically uses the same API key, URL, and model without any duplicate configuration.

---

### 22. [RESOLVED] SQLite database must be manually initialized before starting phpkaiharness

**Problem:**
Developers testing the package noted that the SQLite database had to be manually initialized (via commands or directories creation) before `phpkaiharness` could run. Otherwise, database folder errors or PDO exceptions occurred on fresh installations.

**Resolution:**
Updated `PhpkaiharnessServiceProvider::boot()` to automatically inspect and auto-initialize the SQLite database on boot. If the database file is missing on disk and is not `:memory:`, the provider will automatically construct the required directory structure, generate the empty database file, and instantiate `SqliteMonitorStore` to run the full schema setup.

---

## Summary Table

| # | Severity | Issue | Status |
|---|----------|-------|--------|
| 1 | Critical | No Composer auto-discovery (missing `extra.laravel.providers`) | **Resolved** |
| 2 | Critical | Published config strips all `env()` calls, hardcodes `false` | **Resolved** |
| 3 | Critical | `MonitorReport` crashes on fresh install (no schema init) | **Resolved** |
| 4 | Critical | No `harness:install` artisan command | **Resolved** |
| 5 | High | Not on Packagist, README install instructions fail | **Resolved** |
| 6 | High | `laravel/ai ^0.7.0` forces downgrade from v0.8.x | **Resolved** |
| 7 | High | No container bindings for interfaces | **Resolved** |
| 8 | High | No Laravel Facade | **Resolved** |
| 9 | Medium | Config save via `var_export()` destroys `env()` calls permanently | **Resolved** |
| 10 | Medium | Config key names mismatch between README and code | **Resolved** |
| 11 | Medium | `AgentLoop` auto-config reads undocumented keys | **Resolved** |
| 12 | Medium | `defaultDbPath()` not Laravel-aware on Windows | **Resolved** |
| 13 | Medium | No auth middleware on telemetry routes | **Resolved** |
| 14 | Medium | README references `/harness/playground` route that doesn't exist | **Resolved** |
| 15 | Medium | Production code imports `Mockery` and `PHPUnit` | **Resolved** |
| 16 | Low | No `.gitignore` guidance for SQLite DB | **Resolved** |
| 17 | Low | Guzzle constraint may conflict | **Resolved** |
| 18 | Low | No migration mechanism for monitor DB | **Resolved** |
| 19 | Low | Silent `catch(\Throwable)` with no logging | **Resolved** |
| 20 | Low | Ambiguous return type when streaming | **Resolved** |
| 21 | High | Package uses internal AI provider defaults instead of host app's config | **Resolved** |
| 22 | High | SQLite database must be manually initialized | **Resolved** |

---

## Integration Status
With all issues resolved, manual workarounds (such as manual provider bootstrapping or custom route guards) are **no longer required**. The package integrates cleanly via Composer auto-discovery and standard Artisan installer commands.
