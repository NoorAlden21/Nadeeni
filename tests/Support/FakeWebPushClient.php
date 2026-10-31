<?php

namespace Tests\Support;

use App\Contracts\WebPushClient;
use App\Models\PushSubscription;
use App\Services\WebPushResult;

class FakeWebPushClient implements WebPushClient
{
    public int $sends = 0;

    /** @var list<string> */
    public array $payloads = [];

    public ?WebPushResult $result = null;

    public function send(PushSubscription $subscription, string $payload): WebPushResult
    {
        $this->sends++;
        $this->payloads[] = $payload;

        return $this->result ?? new WebPushResult(true, 201);
    }
}
