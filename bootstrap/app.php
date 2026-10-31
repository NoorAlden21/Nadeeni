<?php

use App\Exceptions\InvalidOrderStatus;
use App\Exceptions\OrderLinkLimit;
use App\Exceptions\OrderUnavailable;
use App\Http\Middleware\EnsureCashier;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'platform.admin' => EnsurePlatformAdmin::class,
            'cashier' => EnsureCashier::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([
            InvalidOrderStatus::class,
            OrderUnavailable::class,
            OrderLinkLimit::class,
        ]);

        $exceptions->render(function (InvalidOrderStatus $exception) {
            return response()->json([
                'message' => 'invalid_status',
            ], 409);
        });

        $exceptions->render(function (OrderUnavailable $exception) {
            return response()->json([
                'message' => 'order_unavailable',
            ], 404);
        });

        $exceptions->render(function (OrderLinkLimit $exception) {
            return response()->json([
                'message' => 'order_link_limit',
            ], 409);
        });
    })
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('orders:expire')->everyMinute()->withoutOverlapping();
    })->create();
