<?php

use App\Enums\OrderStatus;
use App\Exceptions\OrderLinkLimit;
use App\Exceptions\OrderUnavailable;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');

if (config('database.default') !== 'pgsql' || $database !== 'nadeeni_testing') {
    fwrite(STDERR, 'Refusing to run against database ['.$database.'].'.PHP_EOL);
    exit(1);
}

$action = $argv[1] ?? '';

try {
    match ($action) {
        'migrate' => migrateFresh(),
        'setup-duplicate' => setup(false),
        'setup-expire' => setup(true),
        'setup-link-cap' => setupLinkCap(),
        'create' => create($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
        'register' => register($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
        'counts' => counts($argv[2] ?? '', $argv[3] ?? ''),
        'open-tokens' => openTokens($argv[2] ?? '', $argv[3] ?? ''),
        default => throw new RuntimeException('Unknown action.'),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function migrateFresh(): void
{
    $exitCode = Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    if ($exitCode !== 0) {
        fwrite(STDERR, Artisan::output());
        exit($exitCode);
    }
}

function setup(bool $due): void
{
    $restaurant = Restaurant::factory()->create();
    $cashier = User::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    $payload = [
        'user_id' => $cashier->id,
        'restaurant_id' => $restaurant->id,
        'order_number' => '0047',
    ];

    if ($due) {
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'created_by_user_id' => $cashier->id,
            'order_number' => '0047',
            'status' => OrderStatus::Waiting,
            'expires_at' => now()->subMinute(),
        ]);
        $payload['order_id'] = $order->id;
    }

    echo json_encode($payload);
}

function setupLinkCap(): void
{
    $restaurant = Restaurant::factory()->create();
    $cashier = User::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);
    $orders = app(OrderService::class);
    $orders->create($cashier, '0047');
    $orders->registerShared($restaurant, '0047');
    $orders->registerShared($restaurant, '0047');
    $orders->registerShared($restaurant, '0047');

    echo json_encode([
        'restaurant_id' => $restaurant->id,
        'order_number' => '0047',
    ]);
}

function create(string $userId, string $orderNumber, string $readyFile, string $goFile): void
{
    $cashier = User::query()->findOrFail($userId);
    DB::statement("SET lock_timeout = '8s'");
    waitForTurn($readyFile, $goFile);

    try {
        app(OrderService::class)->create($cashier, $orderNumber);
    } catch (ValidationException $exception) {
        $message = $exception->errors()['order_number'][0] ?? '';

        if ($message !== 'An active order with this number already exists.') {
            throw $exception;
        }

        echo 'validation';

        return;
    }

    echo 'created';
}

function register(string $restaurantId, string $orderNumber, string $readyFile, string $goFile): void
{
    $restaurant = Restaurant::query()->findOrFail($restaurantId);
    DB::statement("SET lock_timeout = '8s'");
    waitForTurn($readyFile, $goFile);

    try {
        app(OrderService::class)->registerShared($restaurant, $orderNumber);
    } catch (OrderLinkLimit) {
        echo 'limit';

        return;
    } catch (OrderUnavailable) {
        echo 'unavailable';

        return;
    }

    echo 'linked';
}

function waitForTurn(string $readyFile, string $goFile): void
{
    if ($readyFile === '' || $goFile === '') {
        return;
    }

    file_put_contents($readyFile, 'ready');

    $deadline = microtime(true) + 15;
    while (! is_file($goFile)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the other request.');
        }

        usleep(2000);
    }
}

function counts(string $restaurantId, string $orderNumber): void
{
    $orders = Order::query()
        ->where('restaurant_id', $restaurantId)
        ->where('order_number', $orderNumber)
        ->orderBy('created_at')
        ->get(['id', 'status']);

    echo json_encode($orders->map(fn (Order $order): array => [
        'id' => $order->id,
        'status' => $order->status->value,
    ])->all());
}

function openTokens(string $restaurantId, string $orderNumber): void
{
    $order = Order::query()
        ->where('restaurant_id', $restaurantId)
        ->where('order_number', $orderNumber)
        ->whereIn('status', [OrderStatus::Waiting, OrderStatus::Ready])
        ->firstOrFail();

    echo (string) $order->accessTokens()->whereNull('revoked_at')->count();
}
