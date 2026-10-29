<?php

namespace Tests\Feature;

use App\Enums\PreferredQrMode;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRestaurantTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_platform_admin_can_manage_restaurants(): void
    {
        $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
        ])->assertForbidden()
            ->assertJsonPath('message', 'forbidden');
    }

    public function test_an_admin_can_create_update_and_list_restaurants(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());

        $created = $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'Shawarma-House',
        ]);

        $created->assertCreated()
            ->assertJsonPath('slug', 'shawarma-house')
            ->assertJsonPath('preferred_qr_mode', PreferredQrMode::Shared->value)
            ->assertJsonPath('timezone', 'Asia/Damascus')
            ->assertJsonPath('order_ttl_minutes', 240)
            ->assertJsonPath('is_active', true);

        $restaurantId = $created->json('id');

        $this->patchJson('/api/admin/restaurants/'.$restaurantId, [
            'preferred_qr_mode' => PreferredQrMode::PerOrder->value,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('preferred_qr_mode', PreferredQrMode::PerOrder->value)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('name', 'Shawarma House');

        Restaurant::factory()->create(['name' => 'Alpha Grill', 'slug' => 'alpha-grill']);

        $this->getJson('/api/admin/restaurants')
            ->assertOk()
            ->assertJsonPath('0.slug', 'alpha-grill')
            ->assertJsonPath('1.slug', 'shawarma-house');

        $this->postJson('/api/admin/restaurants', [
            'name' => 'Duplicate',
            'slug' => 'shawarma-house',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_an_admin_can_create_a_cashier_for_a_restaurant(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $restaurant = Restaurant::factory()->create([
            'slug' => 'shawarma-house',
        ]);

        $response = $this->postJson('/api/admin/restaurants/'.$restaurant->id.'/cashiers', [
            'name' => 'Hala',
            'email' => 'hala@shawarma.test',
            'password' => 'cashier-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'hala@shawarma.test')
            ->assertJsonPath('is_platform_admin', false)
            ->assertJsonPath('restaurant.slug', 'shawarma-house')
            ->assertJsonMissingPath('password');

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'hala@shawarma.test',
            'password' => 'cashier-password',
        ])->assertOk()
            ->assertJsonPath('user.restaurant.slug', 'shawarma-house');

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/restaurants/'.$restaurant->id.'/cashiers', [
            'name' => 'Hala Again',
            'email' => 'hala@shawarma.test',
            'password' => 'cashier-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
