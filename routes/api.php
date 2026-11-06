<?php

use App\Http\Controllers\Admin\RestaurantAccountController;
use App\Http\Controllers\Admin\RestaurantController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MenuBannerController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PublicMenuController;
use App\Http\Controllers\PublicOrderController;
use App\Http\Controllers\PublicOrderReviewController;
use App\Http\Controllers\PublicRestaurantController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RestaurantContactController;
use App\Http\Controllers\RestaurantMenuController;
use App\Http\Controllers\RestaurantReviewController;
use App\Http\Controllers\RestaurantStatsController;
use App\Http\Controllers\SharedQrController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::prefix('public')->group(function () {
    Route::get('/restaurants/{slug}', [PublicRestaurantController::class, 'show']);
    Route::get('/restaurants/{slug}/icon', [PublicRestaurantController::class, 'icon']);
    Route::get('/restaurants/{slug}/menu', [PublicMenuController::class, 'show']);
    Route::get('/restaurants/{slug}/banners/{banner}/image', [PublicMenuController::class, 'banner'])
        ->whereNumber('banner');
    Route::get('/restaurants/{slug}/menu-categories/{category}/icon', [PublicMenuController::class, 'icon'])
        ->whereNumber('category');
    Route::get('/restaurants/{slug}/menu-items/{item}/photo', [PublicMenuController::class, 'photo'])
        ->whereNumber('item');
    Route::post('/restaurants/{slug}/register', [PublicRestaurantController::class, 'register'])
        ->middleware('throttle:register');
    Route::get('/orders/{token}', [PublicOrderController::class, 'show'])
        ->middleware('throttle:order-status')
        ->where('token', '[A-Za-z0-9_-]{43}');
    Route::get('/orders/{token}/review', [PublicOrderReviewController::class, 'show'])
        ->middleware('throttle:review')
        ->where('token', '[A-Za-z0-9_-]{43}');
    Route::post('/orders/{token}/review', [PublicOrderReviewController::class, 'store'])
        ->middleware('throttle:review')
        ->where('token', '[A-Za-z0-9_-]{43}');
    Route::post('/orders/{token}/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:push-subscription')
        ->where('token', '[A-Za-z0-9_-]{43}');
    Route::delete('/orders/{token}/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:push-subscription')
        ->where('token', '[A-Za-z0-9_-]{43}');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('platform.admin')->prefix('admin')->group(function () {
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
        Route::get('/subscription-plans/{plan}', [SubscriptionPlanController::class, 'show']);
        Route::patch('/subscription-plans/{plan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/subscription-plans/{plan}', [SubscriptionPlanController::class, 'destroy']);
        Route::get('/restaurants', [RestaurantController::class, 'index']);
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);
        Route::post('/restaurants/{restaurant}/cashiers', [RestaurantController::class, 'storeCashier']);
        Route::post('/restaurants/{restaurant}/owners', [RestaurantController::class, 'storeOwner']);
        Route::get('/restaurants/{restaurant}/accounts', [RestaurantAccountController::class, 'index']);
        Route::get('/accounts/{account}', [RestaurantAccountController::class, 'show']);
        Route::patch('/accounts/{account}', [RestaurantAccountController::class, 'update']);
        Route::delete('/accounts/{account}', [RestaurantAccountController::class, 'destroy']);
    });

    Route::middleware('cashier')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
        Route::patch('/orders/{order}', [OrderController::class, 'update'])->whereUuid('order');
        Route::post('/orders/{order}/notify', [OrderController::class, 'notify'])->whereUuid('order');
        Route::post('/orders/{order}/resend', [OrderController::class, 'resend'])
            ->middleware('throttle:resend')
            ->whereUuid('order');
        Route::post('/orders/{order}/collect', [OrderController::class, 'collect'])->whereUuid('order');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order');
        Route::post('/orders/{order}/uncollect', [OrderController::class, 'uncollect'])->whereUuid('order');
        Route::get('/restaurant/shared-qr', SharedQrController::class);
    });

    Route::middleware('restaurant.staff')->group(function () {
        Route::get('/restaurant/menu', [RestaurantMenuController::class, 'show']);
        Route::get('/restaurant/banners', [MenuBannerController::class, 'index']);
        Route::post('/restaurant/banners', [MenuBannerController::class, 'store']);
        Route::patch('/restaurant/banners/{banner}', [MenuBannerController::class, 'update'])
            ->whereNumber('banner');
        Route::delete('/restaurant/banners/{banner}', [MenuBannerController::class, 'destroy'])
            ->whereNumber('banner');
        Route::post('/restaurant/menu/categories', [MenuCategoryController::class, 'store']);
        Route::patch('/restaurant/menu/categories/{category}', [MenuCategoryController::class, 'update'])
            ->whereNumber('category');
        Route::delete('/restaurant/menu/categories/{category}', [MenuCategoryController::class, 'destroy'])
            ->whereNumber('category');
        Route::post('/restaurant/menu/items', [MenuItemController::class, 'store']);
        Route::patch('/restaurant/menu/items/{item}', [MenuItemController::class, 'update'])
            ->whereNumber('item');
        Route::delete('/restaurant/menu/items/{item}', [MenuItemController::class, 'destroy'])
            ->whereNumber('item');
    });

    Route::middleware('owner')->group(function () {
        Route::get('/restaurant/stats', [RestaurantStatsController::class, 'show']);
        Route::get('/restaurant/reviews', [RestaurantReviewController::class, 'index']);
        Route::patch('/restaurant/contact', [RestaurantContactController::class, 'update']);
    });
});
