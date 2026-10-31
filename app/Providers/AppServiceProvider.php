<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('login', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 5;

            return Limit::perMinute($perMinute)->by($request->ip());
        });

        RateLimiter::for('resend', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 5;

            return Limit::perMinute($perMinute)->by($request->user()?->id.'|'.$request->route('order'));
        });

        RateLimiter::for('register', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 10;

            return Limit::perMinute($perMinute)->by($request->ip());
        });

        RateLimiter::for('order-status', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 30;

            return Limit::perMinute($perMinute)->by((string) $request->route('token'));
        });
    }
}
