<?php

use App\Http\Controllers\Admin\RestaurantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PublicOrderController;
use App\Http\Controllers\PublicRestaurantController;
use App\Http\Controllers\SharedQrController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::prefix('public')->group(function () {
    Route::get('/restaurants/{slug}', [PublicRestaurantController::class, 'show']);
    Route::post('/restaurants/{slug}/register', [PublicRestaurantController::class, 'register'])
        ->middleware('throttle:register');
    Route::get('/orders/{token}', [PublicOrderController::class, 'show'])
        ->middleware('throttle:order-status')
        ->where('token', '[A-Za-z0-9_-]{43}');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('platform.admin')->prefix('admin')->group(function () {
        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);
        Route::post('/restaurants/{restaurant}/cashiers', [RestaurantController::class, 'storeCashier']);
    });

    Route::middleware('cashier')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
        Route::post('/orders/{order}/notify', [OrderController::class, 'notify'])->whereUuid('order');
        Route::post('/orders/{order}/resend', [OrderController::class, 'resend'])
            ->middleware('throttle:resend')
            ->whereUuid('order');
        Route::post('/orders/{order}/collect', [OrderController::class, 'collect'])->whereUuid('order');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order');
        Route::get('/restaurant/shared-qr', SharedQrController::class);
    });
});
