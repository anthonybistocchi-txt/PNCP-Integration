<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\PublicContratations\GetPublicContratationsController;
use Illuminate\Support\Facades\Route;

// Rota pública — sem autenticação, usada por Docker HEALTHCHECK e ferramentas de monitoramento
Route::get('/healthz', [HealthCheckController::class, 'check']);

Route::post('auth/register', [AuthController::class, 'register'])->middleware('verify_permission_to_create_user');

Route::middleware('auth.api')->group(function () {

    Route::prefix('contracts')->group(function () {
        Route::match(['QUERY'], '/', [GetPublicContratationsController::class, 'get'])->middleware('throttle:60,1');
    });
});
