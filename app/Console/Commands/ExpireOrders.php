<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Mark due waiting and ready orders as expired';

    public function handle(OrderService $orders): int
    {
        $count = $orders->expireDue();

        $this->info("Expired {$count} orders.");

        return self::SUCCESS;
    }
}
