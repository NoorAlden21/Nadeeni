<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RestaurantRole;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_leave_one_review_for_a_ready_or_collected_order(): void
    {
        $cashier = User::factory()->create();
        $orders = app(OrderService::class);
        $ready = $orders->create($cashier, '0047');
        $ready->update([
            'status' => OrderStatus::Ready,
            'ready_at' => now(),
        ]);
        $collected = $orders->create($cashier, '0048');
        $collected->update([
            'status' => OrderStatus::Collected,
            'collected_at' => now(),
        ]);
        $waiting = $orders->create($cashier, '0049');
        $cancelled = $orders->create($cashier, '0050');
        $cancelled->update([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $token = $ready->cashierQr->token;

        $this->getJson('/api/public/orders/'.$token.'/review')->assertNotFound();
        $this->getJson('/api/public/orders/'.str_repeat('a', 43).'/review')->assertNotFound();

        $this->postJson('/api/public/orders/'.$waiting->cashierQr->token.'/review', [
            'service_rating' => 5,
            'food_rating' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');

        $this->postJson('/api/public/orders/'.$cancelled->cashierQr->token.'/review', [
            'service_rating' => 5,
            'food_rating' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'invalid_status');

        $created = $this->postJson('/api/public/orders/'.$token.'/review', [
            'service_rating' => 4,
            'food_rating' => 5,
            'message' => '  The wait was fine.  ',
        ]);

        $created->assertOk()
            ->assertJsonPath('service_rating', 4)
            ->assertJsonPath('food_rating', 5)
            ->assertJsonPath('message', 'The wait was fine.');

        $this->getJson('/api/public/orders/'.$token.'/review')
            ->assertOk()
            ->assertJsonPath('message', 'The wait was fine.');

        $this->postJson('/api/public/orders/'.$token.'/review', [
            'service_rating' => 1,
            'food_rating' => 1,
            'message' => 'Changed',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'already_reviewed');

        $this->assertSame('The wait was fine.', $ready->refresh()->review->message);

        $shared = $orders->registerShared($ready->restaurant, '0047');

        $this->postJson('/api/public/orders/'.$shared->token.'/review', [
            'service_rating' => 2,
            'food_rating' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'already_reviewed');

        $this->postJson('/api/public/orders/'.$collected->cashierQr->token.'/review', [
            'service_rating' => 3,
            'food_rating' => 3,
            'message' => '   ',
        ])->assertOk()
            ->assertJsonPath('message', null);

        $this->postJson('/api/public/orders/'.$token.'/review', [
            'service_rating' => 6,
            'food_rating' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['service_rating', 'food_rating']);
    }

    public function test_an_owner_can_read_reviews_and_their_averages(): void
    {
        $cashier = User::factory()->create();
        $owner = User::factory()->create([
            'restaurant_id' => $cashier->restaurant_id,
            'restaurant_role' => RestaurantRole::Owner,
        ]);
        $orders = app(OrderService::class);
        $first = $orders->create($cashier, '0001');
        $second = $orders->create($cashier, '0002');
        $plain = $orders->create($cashier, '0003');

        foreach ([$first, $second, $plain] as $order) {
            $order->update([
                'status' => OrderStatus::Ready,
                'ready_at' => now(),
            ]);
        }

        $this->postJson('/api/public/orders/'.$first->cashierQr->token.'/review', [
            'service_rating' => 5,
            'food_rating' => 3,
            'message' => 'Quick',
        ])->assertOk();
        $first->review->forceFill([
            'created_at' => now()->subMinute(),
        ])->save();

        $this->postJson('/api/public/orders/'.$second->cashierQr->token.'/review', [
            'service_rating' => 4,
            'food_rating' => 5,
            'message' => null,
        ])->assertOk();

        $other = User::factory()->create();
        $otherOrder = $orders->create($other, '0001');
        $otherOrder->update(['status' => OrderStatus::Ready, 'ready_at' => now()]);
        $this->postJson('/api/public/orders/'.$otherOrder->cashierQr->token.'/review', [
            'service_rating' => 1,
            'food_rating' => 1,
            'message' => 'Other restaurant',
        ])->assertOk();

        Sanctum::actingAs($cashier);
        $this->getJson('/api/restaurant/reviews')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/restaurant/reviews')
            ->assertOk()
            ->assertJsonPath('from', null)
            ->assertJsonPath('to', null)
            ->assertJsonPath('reviews', 2)
            ->assertJsonPath('average_service_rating', 4.5)
            ->assertJsonPath('average_food_rating', 4)
            ->assertJsonPath('items.0.order_number', '0002')
            ->assertJsonPath('items.0.service_rating', 4)
            ->assertJsonPath('items.0.message', null)
            ->assertJsonPath('items.1.order_number', '0001')
            ->assertJsonPath('items.1.message', 'Quick');

        $day = Carbon::now('Asia/Damascus')->toDateString();
        $first->review->forceFill([
            'created_at' => Carbon::now('Asia/Damascus')->subDays(3)->utc(),
        ])->save();

        $this->getJson('/api/restaurant/reviews?from='.$day.'&to='.$day)
            ->assertOk()
            ->assertJsonPath('from', $day)
            ->assertJsonPath('to', $day)
            ->assertJsonPath('reviews', 1)
            ->assertJsonPath('average_service_rating', 4)
            ->assertJsonPath('average_food_rating', 5)
            ->assertJsonPath('items.0.order_number', '0002')
            ->assertJsonMissingPath('items.1');

        $this->getJson('/api/restaurant/reviews?from=2026-01-01&to=2026-04-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        $this->getJson('/api/restaurant/stats?from='.$day.'&to='.$day)
            ->assertOk()
            ->assertJsonPath('average_service_rating', 4.5)
            ->assertJsonPath('average_food_rating', 4)
            ->assertJsonPath('days.0.average_service_rating', 4.5)
            ->assertJsonPath('days.0.average_food_rating', 4);
    }
}
