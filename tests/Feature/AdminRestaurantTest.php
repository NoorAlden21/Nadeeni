<?php

namespace Tests\Feature;

use App\Enums\PreferredQrMode;
use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $plan = SubscriptionPlan::factory()->create(['name' => 'Monthly']);

        $created = $this->postJson('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'Shawarma-House',
            'plan_id' => $plan->id,
        ]);

        $created->assertCreated()
            ->assertJsonPath('slug', 'shawarma-house')
            ->assertJsonPath('preferred_qr_mode', PreferredQrMode::Shared->value)
            ->assertJsonPath('timezone', 'Asia/Damascus')
            ->assertJsonPath('order_ttl_minutes', 240)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('subscription.plan_id', $plan->id)
            ->assertJsonPath('subscription.plan_name', 'Monthly')
            ->assertJsonPath('subscription.is_current', true);

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
            'plan_id' => $plan->id,
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

    public function test_an_admin_can_upload_a_restaurant_icon(): void
    {
        Sanctum::actingAs(User::factory()->platformAdmin()->create());
        $plan = SubscriptionPlan::factory()->create();

        $created = $this->post('/api/admin/restaurants', [
            'name' => 'Shawarma House',
            'slug' => 'shawarma-house',
            'plan_id' => $plan->id,
            'icon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ], ['Accept' => 'application/json']);

        $created->assertCreated();
        $iconUrl = $created->json('icon_url');
        $this->assertIsString($iconUrl);
        $this->assertStringContainsString('/api/public/restaurants/shawarma-house/icon?v=', $iconUrl);

        $this->get('/api/public/restaurants/shawarma-house/icon')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->getJson('/api/public/restaurants/shawarma-house')
            ->assertOk()
            ->assertJsonPath('name', 'Shawarma House')
            ->assertJsonPath('icon_url', $iconUrl);

        $replaced = $this->patch('/api/admin/restaurants/'.$created->json('id'), [
            'icon' => UploadedFile::fake()->image('next.jpg', 32, 32),
        ], ['Accept' => 'application/json']);

        $replaced->assertOk();
        $this->assertNotSame($iconUrl, $replaced->json('icon_url'));

        $this->get('/api/public/restaurants/shawarma-house/icon')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->post('/api/admin/restaurants', [
            'name' => 'Bad',
            'slug' => 'bad-icon',
            'plan_id' => $plan->id,
            'icon' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['icon']);

        $this->get('/api/public/restaurants/missing/icon')->assertNotFound();
    }
}
