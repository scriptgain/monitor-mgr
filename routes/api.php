<?php

use App\Http\Controllers\Api\AlertContactController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\HostAgentController;
use App\Http\Controllers\Api\CheckController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\MetricController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\StatusPageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated admin REST API. Bearer token (api_tokens) auth; results are
// scoped to the token owner (admins see everything). Base: /api/v1
Route::prefix('v1')->name('api.')->middleware('api.token')->group(function () {
    Route::get('me', fn (Request $r) => $r->user()->only(['id', 'name', 'email', 'role']));

    // Monitoring (owner-scoped; checks/incidents/metrics inherit their monitor's owner).
    Route::apiResource('monitors', MonitorController::class);
    Route::apiResource('incidents', IncidentController::class)->only(['index', 'show']);
    Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
    Route::apiResource('checks', CheckController::class)->only(['index', 'show']);
    Route::apiResource('metrics', MetricController::class)->only(['index', 'show']);
    Route::apiResource('alert-contacts', AlertContactController::class)->parameters(['alert-contacts' => 'alertContact']);
    Route::apiResource('status-pages', StatusPageController::class);

    // Administration.
    Route::apiResource('users', UserController::class);
    Route::apiResource('api-tokens', ApiTokenController::class)->only(['index', 'store', 'destroy'])->parameters(['api-tokens' => 'apiToken']);
});

// Host-agent API. MonitorMGR agents dial out to these. Enroll is one-time-token
// based; ingest uses the per-host agent key. Base: /api/agent/v1
Route::prefix('agent/v1')->name('agent.')->group(function () {
    Route::post('enroll', [HostAgentController::class, 'enroll']);
    Route::middleware('agent.host')->group(function () {
        Route::post('metrics', [HostAgentController::class, 'ingest']);
    });
});
