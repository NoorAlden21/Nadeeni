<?php

namespace Tests\Feature;

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_platform_admin_can_manage_restaurant_accounts(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Cashier,
        ]);

        $this->getJson('/api/admin/restaurants/'.$restaurant->id.'/accounts')->assertUnauthorized();

        Sanctum::actingAs($cashier);

        $this->getJson('/api/admin/restaurants/'.$restaurant->id.'/accounts')
            ->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        $this->patchJson('/api/admin/accounts/'.$cashier->id, [
            'name' => 'Changed',
        ])->assertForbidden();

        $this->deleteJson('/api/admin/accounts/'.$cashier->id)->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $cashier->id,
            'name' => $cashier->name,
        ]);
    }

    public function test_an_admin_can_list_show_update_and_delete_restaurant_accounts(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        $other = Restaurant::factory()->create();

        $cashier = User::factory()->create([
            'name' => 'Hala',
            'email' => 'hala@shawarma.test',
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Cashier,
        ]);
        $owner = User::factory()->create([
            'name' => 'Nabil',
            'email' => 'nabil@shawarma.test',
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Owner,
        ]);
        User::factory()->create([
            'name' => 'Other',
            'email' => 'other@grill.test',
            'restaurant_id' => $other->id,
        ]);

        $token = $cashier->createToken('cashier')->plainTextToken;

        $this->getJson('/api/admin/restaurants/'.$restaurant->id.'/accounts')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.email', 'hala@shawarma.test')
            ->assertJsonPath('0.restaurant_role', 'cashier')
            ->assertJsonPath('0.restaurant.slug', 'shawarma-house')
            ->assertJsonPath('1.email', 'nabil@shawarma.test')
            ->assertJsonMissingPath('0.password');

        $this->getJson('/api/admin/accounts/'.$cashier->id)
            ->assertOk()
            ->assertJsonPath('email', 'hala@shawarma.test');

        $this->getJson('/api/admin/accounts/'.$admin->id)->assertNotFound();

        $this->patchJson('/api/admin/accounts/'.$cashier->id, [
            'name' => 'Hala N',
            'email' => 'hala.n@shawarma.test',
            'password' => 'new-password',
            'restaurant_role' => 'owner',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Hala N')
            ->assertJsonPath('email', 'hala.n@shawarma.test')
            ->assertJsonPath('restaurant_role', 'owner')
            ->assertJsonPath('is_active', false)
            ->assertJsonMissingPath('password');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => 'hala.n@shawarma.test',
            'password' => 'new-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'inactive');

        $this->postJson('/api/auth/login', [
            'email' => 'hala@shawarma.test',
            'password' => 'password',
        ])->assertUnauthorized();

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/accounts/'.$cashier->id, [
            'email' => 'nabil@shawarma.test',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->patchJson('/api/admin/accounts/'.$admin->id, [
            'name' => 'Stolen',
        ])->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'name' => $admin->name,
        ]);

        $this->deleteJson('/api/admin/accounts/'.$owner->id)
            ->assertOk()
            ->assertJsonPath('message', 'deleted');

        $this->assertDatabaseMissing('users', [
            'id' => $owner->id,
        ]);

        $this->deleteJson('/api/admin/accounts/'.$admin->id)->assertNotFound();
        $this->getJson('/api/admin/accounts/99999')->assertNotFound();
    }
}
