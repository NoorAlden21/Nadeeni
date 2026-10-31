<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public bool $includeCashierQr = false;

    public ?bool $alreadyNotified = null;

    public static function withCashierQr(Order $order): self
    {
        $resource = new self($order);
        $resource->includeCashierQr = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toISOString(),
            'ready_at' => $this->ready_at?->toISOString(),
            'collected_at' => $this->collected_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'expired_at' => $this->expired_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($this->includeCashierQr) {
            $token = $this->cashierQr;

            $data['cashier_qr'] = [
                'token' => $token->token,
                'url' => FrontendUrl::order($token->token),
            ];
        }

        if ($this->alreadyNotified !== null) {
            $data['already_notified'] = $this->alreadyNotified;
        }

        return $data;
    }
}
