<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Infra\HealthCheckController;
use App\Http\Controllers\Orgaos\GetOrgaoController;
use App\Http\Controllers\Orgaos\ListOrgaosController;
use App\Http\Controllers\Pca\ListPcaItemsController;
use App\Http\Controllers\Contratacoes\ListContratacoesController;
use App\Http\Controllers\Contratacoes\ListRelevantContratacoesController;
use App\Http\Controllers\UserContratationFilters\DeleteUserContratationFilterController;
use App\Http\Controllers\UserContratationFilters\ListUserContratationFiltersController;
use App\Http\Controllers\UserContratationFilters\SaveOrUpdateUserContratationFilterController;
use App\Http\Controllers\UserKeywords\DeleteUserKeywordController;
use App\Http\Controllers\UserKeywords\ListUserKeywordsController;
use App\Http\Controllers\UserKeywords\SaveOrUpdateUserKeywordController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HealthCheckController::class, 'check'])->middleware('throttle:60,1');

Route::middleware('auth.api')->group(function () {

    Route::get('/me', [MeController::class, 'me'])->middleware('throttle:60,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('verify_permission_to_create_user', 'throttle:60,1');

    Route::prefix('v1/')->group(function () {

        Route::prefix('keywords')->group(function () {
            Route::get('/', [ListUserKeywordsController::class, 'listByUserId'])->middleware('throttle:60,1');
            Route::post('/', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
            Route::put('/{id}', [SaveOrUpdateUserKeywordController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
            Route::delete('/{id}', [DeleteUserKeywordController::class, 'delete'])->middleware('throttle:60,1');
        });

        Route::prefix('filters')->group(function () {
            Route::get('/', [ListUserContratationFiltersController::class, 'listByUserId'])->middleware('throttle:60,1');
            Route::post('/', [SaveOrUpdateUserContratationFilterController::class, 'saveOrUpdate'])->middleware('throttle:60,1');
            Route::delete('/{id}', [DeleteUserContratationFilterController::class, 'delete'])->middleware('throttle:60,1');
        });

        Route::prefix('contratacoes')->group(function () {
            Route::get('/relevant/include', [ListRelevantContratacoesController::class, 'listInclude'])->middleware('throttle:60,1');
            Route::get('/relevant/both', [ListRelevantContratacoesController::class, 'listBoth'])->middleware('throttle:60,1');
            Route::match(['QUERY'], '/', [ListContratacoesController::class, 'list'])->middleware('throttle:60,1');
        });

        Route::prefix('pca')->group(function () {
            Route::match(['QUERY', 'GET'], '/items', [ListPcaItemsController::class, 'list'])->middleware('throttle:60,1');
        });

        Route::prefix('orgaos')->group(function () {
            Route::match(['QUERY'], '/', [ListOrgaosController::class, 'list'])->middleware('throttle:60,1');
            Route::match(['QUERY'], '/{cnpj}', [GetOrgaoController::class, 'show'])->middleware('throttle:60,1');
        });

    });
});
