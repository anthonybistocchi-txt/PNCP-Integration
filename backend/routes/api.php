<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicContratations\GetPublicContratationsController;
use Illuminate\Support\Facades\Route;


Route::post('auth/register', [AuthController::class, 'register'])->middleware('verify_permission_to_create_user');

Route::middleware('auth.api')->group(function () {

    Route::prefix('contracts')->group(function () {
        Route::get('/public', [GetPublicContratationsController::class, 'get'])->middleware('throttle:60,1');
    });
});
