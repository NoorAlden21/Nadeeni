<?php

namespace App\Contracts;

use App\Models\PushSubscription;
use App\Services\WebPushResult;

interface WebPushClient
{
    public function send(PushSubscription $subscription, string $payload): WebPushResult;
}
