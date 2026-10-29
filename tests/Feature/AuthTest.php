<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_can_log_in_read_me_and_log_out(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'shawarma-house',
        ]);
        $cashier = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'password' => 'secret-password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $cashier->email,
            'password' => 'secret-password',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.email', $cashier->email)
            ->assertJsonPath('user.is_platform_admin', false)
            ->assertJsonPath('user.restaurant.slug', 'shawarma-house')
            ->assertJsonPath('user.restaurant.name', $restaurant->name);

        $token = $login->json('token');
        $this->assertIsString($token);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', $cashier->email);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'logged_out');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_login_rejects_bad_passwords_and_inactive_accounts(): void
    {
        $cashier = User::factory()->create([
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $cashier->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'invalid_credentials');

        $cashier->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $cashier->email,
            'password' => 'secret-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'inactive');

        $restaurant = Restaurant::factory()->create(['is_active' => false]);
        $blocked = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $blocked->email,
            'password' => 'secret-password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'inactive');
    }

    public function test_me_requires_a_token_and_platform_admins_have_no_restaurant(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();

        $admin = User::factory()->platformAdmin()->create([
            'password' => 'secret-password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('is_platform_admin', true)
            ->assertJsonPath('restaurant', null);
    }

    public function test_the_platform_admin_seeder_creates_one_admin(): void
    {
        config([
            'nadeeni.admin.name' => 'Platform Admin',
            'nadeeni.admin.email' => 'admin@nadeeni.test',
            'nadeeni.admin.password' => 'local-admin-password',
        ]);

        $this->seed(PlatformAdminSeeder::class);
        $this->seed(PlatformAdminSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'admin@nadeeni.test')->count());

        $admin = User::query()->where('email', 'admin@nadeeni.test')->first();
        $this->assertTrue($admin->is_platform_admin);
        $this->assertNull($admin->restaurant_id);
    }
}
