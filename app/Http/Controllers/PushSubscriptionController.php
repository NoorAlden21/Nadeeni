<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeletePushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\OrderAccessToken;
use App\Services\PushNotifier;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    public function __construct(private PushNotifier $push) {}

    public function store(StorePushSubscriptionRequest $request, string $token): JsonResponse
    {
        $accessToken = $this->usableToken($token);

        if ($accessToken === null) {
            return $this->notFound();
        }

        $created = $this->push->subscribe($accessToken, [
            'endpoint' => $request->string('endpoint')->toString(),
            'p256dh' => $request->string('keys.p256dh')->toString(),
            'auth' => $request->string('keys.auth')->toString(),
            'content_encoding' => $request->input('content_encoding'),
            'expirationTime' => $request->input('expirationTime'),
        ]);

        return response()->json([
            'subscribed' => true,
        ], $created ? 201 : 200);
    }

    public function destroy(DeletePushSubscriptionRequest $request, string $token): JsonResponse
    {
        $accessToken = $this->usableToken($token);

        if ($accessToken === null) {
            return $this->notFound();
        }

        $deleted = $this->push->unsubscribe($accessToken, $request->string('endpoint')->toString());

        if (! $deleted) {
            return $this->notFound();
        }

        return response()->json([
            'message' => 'deleted',
        ]);
    }

    private function usableToken(string $token): ?OrderAccessToken
    {
        $accessToken = OrderAccessToken::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($accessToken === null) {
            return null;
        }

        $accessToken->load('order');

        if (! $accessToken->order->status->isActive()) {
            return null;
        }

        return $accessToken;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Not found.',
        ], 404);
    }
}
