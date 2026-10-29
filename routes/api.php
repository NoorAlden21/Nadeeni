<?php

use App\Http\Controllers\Admin\RestaurantController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('platform.admin')->prefix('admin')->group(function () {
        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);
        Route::post('/restaurants/{restaurant}/cashiers', [RestaurantController::class, 'storeCashier']);
    });
});
