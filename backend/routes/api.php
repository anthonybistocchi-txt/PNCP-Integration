<?php

use App\Http\Controllers\PublicContratations\GetPublicContratationsController;
use Illuminate\Support\Facades\Route;


Route::middleware('auth.api')->group(function () {
    Route::prefix('contracts')->group(function () {
        Route::get('/public', [GetPublicContratationsController::class, 'get'])->middleware('throttle:60,1');
    });
});
