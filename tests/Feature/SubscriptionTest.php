<?php

namespace Tests\Feature;

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_platform_admin_can_manage_plans(): void
    {
        $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Monthly',
            'interval' => 'month',
            'price' => 25,
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/subscription-plans')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');
    }

    public function test_an_admin_can_manage_plans_and_assign_them_to_a_restaurant(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $monthly = $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Monthly',
            'interval' => 'month',
            'price' => 25,
            'currency' => 'usd',
        ]);

        $monthly->assertCreated()
            ->assertJsonPath('interval', 'month')
            ->assertJsonPath('duration_days', null)
            ->assertJsonPath('price', 25)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('is_active', true);

        $trial = $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Trial 14',
            'interval' => 'trial',
            'duration_days' => 14,
            'price' => 0,
        ]);

        $trial->assertCreated()
            ->assertJsonPath('duration_days', 14);

        $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Trial missing days',
            'interval' => 'trial',
            'price' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_days']);

        $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Monthly again',
            'interval' => 'month',
            'duration_days' => 30,
            'price' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_days']);

        $planId = $monthly->json('id');

        $this->patchJson('/api/admin/subscription-plans/'.$planId, [
            'price' => 30,
        ])->assertOk()
            ->assertJsonPath('price', 30)
            ->assertJsonPath('name', 'Monthly');

        $this->getJson('/api/admin/subscription-plans')
            ->assertOk()
            ->assertJsonPath('0.name', 'Monthly')
            ->assertJsonPath('1.name', 'Trial 14');

        $created = $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
            'plan_id' => $planId,
        ]);

        $created->assertCreated()
            ->assertJsonPath('subscription.plan_name', 'Monthly')
            ->assertJsonPath('subscription.is_current', true)
            ->assertJsonPath('subscription.ends_at', '2026-12-01T12:00:00.000000Z');

        $restaurantId = $created->json('id');

        $this->patchJson('/api/admin/restaurants/'.$restaurantId, [
            'plan_id' => $trial->json('id'),
        ])->assertOk()
            ->assertJsonPath('subscription.plan_name', 'Trial 14')
            ->assertJsonPath('subscription.starts_at', '2026-12-01T12:00:00.000000Z')
            ->assertJsonPath('subscription.ends_at', '2026-12-15T12:00:00.000000Z');

        $this->postJson('/api/admin/restaurants', [
            'name' => 'No Plan',
            'slug' => 'no-plan',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);

        SubscriptionPlan::query()->whereKey($planId)->update(['is_active' => false]);

        $this->postJson('/api/admin/restaurants', [
            'name' => 'Closed Plan',
            'slug' => 'closed-plan',
            'plan_id' => $planId,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);

        $this->deleteJson('/api/admin/subscription-plans/'.$trial->json('id'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'plan_in_use');

        $unused = SubscriptionPlan::factory()->create(['name' => 'Unused']);

        $this->deleteJson('/api/admin/subscription-plans/'.$unused->id)
            ->assertOk()
            ->assertJsonPath('message', 'deleted');
    }

    public function test_a_restaurant_without_a_current_subscription_cannot_use_the_system(): void
    {
        $restaurant = Restaurant::factory()->withoutSubscription()->create([
            'slug' => 'shawarma-house',
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Cashier,
            'email' => 'hala@shawarma.test',
            'password' => 'cashier-password',
        ]);
        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Owner,
            'email' => 'nabil@shawarma.test',
            'password' => 'owner-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'hala@shawarma.test',
            'password' => 'cashier-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'subscription_inactive');

        Sanctum::actingAs($cashier);

        $this->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'subscription_inactive');

        Sanctum::actingAs($owner);

        $this->getJson('/api/restaurant/stats?from=2026-11-01&to=2026-11-01')
            ->assertForbidden()
            ->assertJsonPath('message', 'subscription_inactive');

        $this->getJson('/api/public/restaurants/shawarma-house')->assertNotFound();

        $expired = Restaurant::factory()->expiredSubscription()->create([
            'slug' => 'expired-house',
        ]);
        User::factory()->create([
            'restaurant_id' => $expired->id,
            'email' => 'expired@shawarma.test',
            'password' => 'cashier-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'expired@shawarma.test',
            'password' => 'cashier-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'subscription_inactive');

        $this->getJson('/api/public/restaurants/expired-house')->assertNotFound();
    }
}
