<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_features_are_copied_onto_the_restaurant_and_gate_the_routes(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $this->actAs($admin);

        $pager = $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Pager',
            'interval' => 'month',
            'price' => 10,
            'pager' => true,
            'menu' => false,
        ])->assertCreated()
            ->assertJsonPath('pager', true)
            ->assertJsonPath('menu', false)
            ->assertJsonPath('ordering', false)
            ->assertJsonPath('kitchen', false);

        $this->postJson('/api/admin/subscription-plans', [
            'name' => 'Ordering without menu',
            'interval' => 'month',
            'price' => 10,
            'ordering' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['ordering']);

        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        $this->patchJson('/api/admin/restaurants/'.$restaurant->id, [
            'plan_id' => $pager->json('id'),
        ])->assertOk()
            ->assertJsonPath('features.pager', true)
            ->assertJsonPath('features.menu', false);

        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->actAs($cashier);

        $this->postJson('/api/orders', ['order_number' => '0047'])->assertCreated();
        $this->postJson('/api/restaurant/menu/categories', [
            'name_ar' => 'مشاوي',
        ])->assertForbidden()
            ->assertJsonPath('message', 'feature_unavailable');

        $this->getJson('/api/public/restaurants/shawarma-house')
            ->assertOk()
            ->assertJsonPath('features.menu', false);

        $this->getJson('/api/public/restaurants/shawarma-house/menu')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        $menu = SubscriptionPlan::factory()->create([
            'name' => 'Menu only',
            'pager' => false,
            'menu' => true,
        ]);
        $menuRestaurant = Restaurant::factory()->create(['slug' => 'menu-house']);
        $menuRestaurant->grant($menu);
        $menuCashier = User::factory()->create(['restaurant_id' => $menuRestaurant->id]);
        $this->actAs($menuCashier);

        $this->postJson('/api/orders', ['order_number' => '0047'])
            ->assertForbidden()
            ->assertJsonPath('message', 'feature_unavailable');
        $this->postJson('/api/public/restaurants/menu-house/register', [
            'order_number' => '0047',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
        $this->getJson('/api/public/restaurants/menu-house/menu')
            ->assertOk()
            ->assertJsonPath('categories', []);

        $this->actAs($admin);
        $this->patchJson('/api/admin/subscription-plans/'.$pager->json('id'), [
            'menu' => true,
        ])->assertOk()
            ->assertJsonPath('menu', true);

        $this->assertTrue($restaurant->refresh()->menu);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
