<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\PublicContratations\GetPublicContratationsController;
use App\Http\Controllers\PublicContratations\GetRelevantContratationsController;
use App\Http\Controllers\UserKeywords\SaveOrUpdateUserKeywordController;
use App\Http\Controllers\UserKeywords\ListUserKeywordsController;
use App\Http\Controllers\UserKeywords\DeleteUserKeywordController;
use Illuminate\Support\Facades\Route;

// Rota pública — sem autenticação, usada por Docker HEALTHCHECK e ferramentas de monitoramento
Route::get('/healthz', [HealthCheckController::class, 'check']);

Route::post('auth/register', [AuthController::class, 'register'])->middleware('verify_permission_to_create_user');

Route::middleware('auth.api')->group(function () {

    Route::prefix('v1/keywords')->group(function () {
        Route::get('/', [ListUserKeywordsController::class, 'get'])->middleware('throttle:60,1');
        Route::post('/', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
        Route::put('/{id}', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
        Route::delete('/{id}', [DeleteUserKeywordController::class, 'delete'])->middleware('throttle:60,1');
    });

    Route::prefix('v1/contracts')->group(function () {
        Route::get('/relevant', [GetRelevantContratationsController::class, 'get'])->middleware('throttle:60,1');
        Route::match(['QUERY'], '/', [GetPublicContratationsController::class, 'get'])->middleware('throttle:60,1');
    });
});
