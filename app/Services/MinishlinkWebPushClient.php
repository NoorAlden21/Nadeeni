<?php

namespace App\Services;

use App\Contracts\WebPushClient;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class MinishlinkWebPushClient implements WebPushClient
{
    public function send(PushSubscription $subscription, string $payload): WebPushResult
    {
        $subject = config('nadeeni.vapid.subject');
        $publicKey = config('nadeeni.vapid.public_key');
        $privateKey = config('nadeeni.vapid.private_key');

        if (! is_string($subject) || $subject === ''
            || ! is_string($publicKey) || $publicKey === ''
            || ! is_string($privateKey) || $privateKey === '') {
            return new WebPushResult(false);
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                $payload,
            );
        } catch (Throwable) {
            return new WebPushResult(false);
        }

        return new WebPushResult(
            $report->isSuccess(),
            $report->getResponse()?->getStatusCode(),
        );
    }
}
