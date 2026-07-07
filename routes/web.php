<?php

use Illuminate\Support\Facades\Route;
use Phpkaiharness\Http\Controllers\HarnessConfigController;
use Phpkaiharness\Http\Controllers\HarnessTelemetryController;
use Phpkaiharness\Http\Middleware\Authorize;

/**
 * phpkaiharness Telemetry & Configuration Routes.
 *
 * These routes are automatically registered by the PhpkaiharnessServiceProvider
 * when the telemetry feature is enabled in config.
 */
$prefix = config('harness.telemetry.route_prefix', 'harness');
$middleware = config('harness.telemetry.middleware', ['web']);
$middleware[] = Authorize::class;

Route::group(['prefix' => $prefix, 'middleware' => $middleware], static function (): void {

    // HTML Dashboard (telemetry + tracing)
    Route::get('dashboard', [HarnessTelemetryController::class, 'dashboard'])
        ->name('harness.dashboard');

    // Catch-all API Endpoint for AJAX dashboard (?action=...)
    Route::any('api', [HarnessTelemetryController::class, 'api'])
        ->name('harness.api');

    // Configuration UI
    Route::get('config', [HarnessConfigController::class, 'index'])
        ->name('harness.config');

    Route::post('config', [HarnessConfigController::class, 'save'])
        ->name('harness.config.save');

    // REST API Endpoints
    Route::prefix('api')->group(static function (): void {
        Route::get('stats', [HarnessTelemetryController::class, 'stats'])
            ->name('harness.api.stats');

        Route::get('sessions', [HarnessTelemetryController::class, 'sessions'])
            ->name('harness.api.sessions');

        Route::get('sessions/{id}', [HarnessTelemetryController::class, 'show'])
            ->name('harness.api.session.show');

        // Isolated Session Management
        Route::get('sessions-list', [HarnessTelemetryController::class, 'listIsolatedSessions'])
            ->name('harness.api.sessions.list');

        Route::get('sessions-list/{sessionId}', [HarnessTelemetryController::class, 'showIsolatedSession'])
            ->name('harness.api.sessions.show');

        Route::delete('sessions-list/{sessionId}', [HarnessTelemetryController::class, 'deleteIsolatedSession'])
            ->name('harness.api.sessions.delete');

        Route::post('sessions-purge', [HarnessTelemetryController::class, 'purgeAllSessions'])
            ->name('harness.api.sessions.purge');

        Route::post('sessions-cleanup', [HarnessTelemetryController::class, 'cleanupOldSessions'])
            ->name('harness.api.sessions.cleanup');
    });

});
