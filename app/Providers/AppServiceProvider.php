<?php

namespace App\Providers;

use App\Contracts\WebPushClient;
use App\Services\MinishlinkWebPushClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WebPushClient::class, MinishlinkWebPushClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

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

        RateLimiter::for('review', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 10;

            return Limit::perMinute($perMinute)->by((string) $request->route('token'));
        });

        RateLimiter::for('push-subscription', function (Request $request) {
            $perMinute = app()->runningUnitTests() ? 100 : 10;

            return Limit::perMinute($perMinute)->by((string) $request->route('token'));
        });
    }
}
