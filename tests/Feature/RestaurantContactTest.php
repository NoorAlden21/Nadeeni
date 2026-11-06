<?php

namespace Tests\Feature;

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_fields_start_empty_and_an_admin_can_set_and_clear_them(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $plan = SubscriptionPlan::factory()->create();

        $created = $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
            'plan_id' => $plan->id,
            'facebook_url' => 'https://facebook.com/shawarmahouse',
            'instagram_url' => ' https://www.instagram.com/shawarmahouse ',
            'phone' => '+963 944 123 456',
        ]);

        $created->assertCreated()
            ->assertJsonPath('facebook_url', 'https://facebook.com/shawarmahouse')
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/shawarmahouse')
            ->assertJsonPath('phone', '+963 944 123 456');

        $restaurantId = $created->json('id');

        $this->patchJson('/api/admin/restaurants/'.$restaurantId, [
            'phone' => '0944123456',
        ])->assertOk()
            ->assertJsonPath('phone', '0944123456')
            ->assertJsonPath('facebook_url', 'https://facebook.com/shawarmahouse')
            ->assertJsonPath('instagram_url', 'https://www.instagram.com/shawarmahouse');

        $this->patchJson('/api/admin/restaurants/'.$restaurantId, [
            'facebook_url' => null,
            'instagram_url' => null,
            'phone' => null,
        ])->assertOk()
            ->assertJsonPath('facebook_url', null)
            ->assertJsonPath('instagram_url', null)
            ->assertJsonPath('phone', null);

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurantId,
            'facebook_url' => null,
            'instagram_url' => null,
            'phone' => null,
        ]);

        $this->patchJson('/api/admin/restaurants/'.$restaurantId, [
            'facebook_url' => 'https://example.com/not-facebook',
            'instagram_url' => 'not-a-link',
            'phone' => 'call me',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['facebook_url', 'instagram_url', 'phone']);
    }

    public function test_an_owner_can_patch_contact_details_for_their_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ]);
        $other = Restaurant::factory()->create(['slug' => 'other-grill']);
        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Owner,
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Cashier,
        ]);

        Sanctum::actingAs($cashier);
        $this->patchJson('/api/restaurant/contact', [
            'phone' => '0944123456',
        ])->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $this->patchJson('/api/restaurant/contact', [
            'phone' => '0944123456',
        ])->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        Sanctum::actingAs($owner);
        $this->patchJson('/api/restaurant/contact', [
            'name' => 'Renamed',
            'facebook_url' => 'https://m.facebook.com/shawarmahouse',
            'instagram_url' => 'https://instagram.com/shawarmahouse',
            'phone' => '+963944123456',
        ])->assertOk()
            ->assertJsonPath('name', 'Shawarma House')
            ->assertJsonPath('facebook_url', 'https://m.facebook.com/shawarmahouse')
            ->assertJsonPath('instagram_url', 'https://instagram.com/shawarmahouse')
            ->assertJsonPath('phone', '+963944123456');

        $this->assertSame('Shawarma House', $restaurant->refresh()->name);
        $this->assertNull($other->refresh()->phone);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('restaurant.facebook_url', 'https://m.facebook.com/shawarmahouse')
            ->assertJsonPath('restaurant.instagram_url', 'https://instagram.com/shawarmahouse')
            ->assertJsonPath('restaurant.phone', '+963944123456');

        $this->getJson('/api/public/restaurants/shawarma-house')
            ->assertOk()
            ->assertJsonPath('facebook_url', 'https://m.facebook.com/shawarmahouse')
            ->assertJsonPath('instagram_url', 'https://instagram.com/shawarmahouse')
            ->assertJsonPath('phone', '+963944123456');

        $this->patchJson('/api/restaurant/contact', [
            'phone' => null,
        ])->assertOk()
            ->assertJsonPath('phone', null)
            ->assertJsonPath('facebook_url', 'https://m.facebook.com/shawarmahouse');
    }
}
