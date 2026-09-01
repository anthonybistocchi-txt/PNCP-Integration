<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Infra\HealthCheckController;
use App\Http\Controllers\PublicContratations\ListPublicContratationsController;
use App\Http\Controllers\PublicContratations\ListRelevantContratationsController;
use App\Http\Controllers\UserKeywords\SaveOrUpdateUserKeywordController;
use App\Http\Controllers\UserKeywords\ListUserKeywordsController;
use App\Http\Controllers\UserKeywords\DeleteUserKeywordController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.api')->group(function () {

    Route::get('/healthz', [HealthCheckController::class, 'check'])->middleware('throttle:60,1');
    Route::get('/me', [MeController::class, 'me'])->middleware('throttle:60,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('verify_permission_to_create_user', 'throttle:60,1');

    Route::prefix('v1/keywords')->group(function () {
        Route::get('/', [ListUserKeywordsController::class, 'listByUserId'])->middleware('throttle:60,1');
        Route::post('/', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
        Route::put('/{id}', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
        Route::delete('/{id}', [DeleteUserKeywordController::class, 'delete'])->middleware('throttle:60,1');
    });

    Route::prefix('v1/contracts')->group(function () {
        Route::get('/relevant', [ListRelevantContratationsController::class, 'list'])->middleware('throttle:60,1');
        Route::match(['QUERY'], '/', [ListPublicContratationsController::class, 'list'])->middleware('throttle:60,1');
    });
});

