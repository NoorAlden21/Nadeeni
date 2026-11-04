<?php

namespace Tests\Feature;

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_and_an_owner_manage_one_restaurant_menu(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_role' => RestaurantRole::Owner,
        ]);
        $other = User::factory()->create([
            'restaurant_id' => Restaurant::factory()->create(['slug' => 'other-grill'])->id,
        ]);

        $this->postJson('/api/restaurant/menu/categories', [
            'name_ar' => 'مشاوي',
        ])->assertUnauthorized();

        $this->actAs(User::factory()->platformAdmin()->create());
        $this->postJson('/api/restaurant/menu/categories', [
            'name_ar' => 'مشاوي',
        ])->assertForbidden()
            ->assertJsonPath('message', 'forbidden');

        $this->actAs($cashier);

        $category = $this->post('/api/restaurant/menu/categories', [
            'name_ar' => '  مشاوي  ',
            'name_en' => '',
            'sort_order' => 2,
            'icon' => UploadedFile::fake()->image('grill.png', 32, 32),
        ], ['Accept' => 'application/json']);

        $category->assertCreated()
            ->assertJsonPath('name.ar', 'مشاوي')
            ->assertJsonPath('name.en', null)
            ->assertJsonPath('sort_order', 2)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('items', []);

        $categoryId = $category->json('id');
        $iconUrl = $category->json('icon_url');
        $this->assertIsString($iconUrl);
        $this->assertStringContainsString('/api/public/restaurants/shawarma-house/menu-categories/'.$categoryId.'/icon?v=', $iconUrl);

        $this->get('/api/public/restaurants/shawarma-house/menu-categories/'.$categoryId.'/icon')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $hidden = $this->postJson('/api/restaurant/menu/categories', [
            'name_ar' => 'مشروبات',
            'name_en' => 'Drinks',
            'sort_order' => 1,
            'is_active' => false,
        ])->assertCreated();

        $item = $this->post('/api/restaurant/menu/items', [
            'category_id' => $categoryId,
            'name_ar' => 'شاورما دجاج',
            'name_en' => 'Chicken shawarma',
            'description_ar' => 'مع ثوم',
            'price' => 25000,
            'badge' => 'offer',
            'photo' => UploadedFile::fake()->image('shawarma.jpg', 40, 40),
        ], ['Accept' => 'application/json']);

        $item->assertCreated()
            ->assertJsonPath('category_id', $categoryId)
            ->assertJsonPath('name.ar', 'شاورما دجاج')
            ->assertJsonPath('name.en', 'Chicken shawarma')
            ->assertJsonPath('description.ar', 'مع ثوم')
            ->assertJsonPath('description.en', null)
            ->assertJsonPath('price', 25000)
            ->assertJsonPath('is_available', true)
            ->assertJsonPath('badge', 'offer');

        $itemId = $item->json('id');
        $photoUrl = $item->json('photo_url');
        $this->assertIsString($photoUrl);
        $this->get('/api/public/restaurants/shawarma-house/menu-items/'.$itemId.'/photo')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->postJson('/api/restaurant/menu/items', [
            'category_id' => $categoryId,
            'name_ar' => 'شاورما لحم',
            'price' => 30000,
            'is_available' => false,
            'sort_order' => 1,
        ])->assertCreated()
            ->assertJsonPath('badge', null);

        $this->postJson('/api/restaurant/menu/items', [
            'category_id' => $categoryId,
            'name_ar' => 'خطأ',
            'price' => 1,
            'badge' => 'sale',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['badge']);

        $this->postJson('/api/restaurant/menu/items', [
            'category_id' => $hidden->json('id'),
            'name_ar' => 'عصير',
            'price' => 5000,
        ])->assertCreated();

        $this->post('/api/restaurant/menu/items', [
            'category_id' => $categoryId,
            'name_ar' => 'ملف',
            'price' => 1,
            'photo' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);

        $this->actAs($other);
        $this->patchJson('/api/restaurant/menu/categories/'.$categoryId, [
            'name_ar' => 'لا',
        ])->assertNotFound();
        $this->postJson('/api/restaurant/menu/items', [
            'category_id' => $categoryId,
            'name_ar' => 'لا',
            'price' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $this->actAs($owner);

        $this->patchJson('/api/restaurant/menu/items/'.$itemId, [
            'price' => 27000,
            'description_en' => 'With garlic',
            'badge' => null,
        ])->assertOk()
            ->assertJsonPath('price', 27000)
            ->assertJsonPath('description.en', 'With garlic')
            ->assertJsonPath('badge', null)
            ->assertJsonPath('photo_url', $photoUrl);

        $this->deleteJson('/api/restaurant/menu/categories/'.$categoryId)
            ->assertStatus(409)
            ->assertJsonPath('message', 'category_not_empty');

        $menu = $this->getJson('/api/restaurant/menu')->assertOk()->json();
        $this->assertSame('مشروبات', $menu[0]['name']['ar']);
        $this->assertSame('مشاوي', $menu[1]['name']['ar']);
        $this->assertCount(2, $menu[1]['items']);
        $this->assertFalse($menu[0]['is_active']);
        $this->assertFalse($menu[1]['items'][1]['is_available']);

        $public = $this->getJson('/api/public/restaurants/shawarma-house/menu')->assertOk()->json();
        $this->assertCount(1, $public);
        $this->assertSame('مشاوي', $public[0]['name']['ar']);
        $this->assertSame($iconUrl, $public[0]['icon_url']);
        $this->assertCount(1, $public[0]['items']);
        $this->assertSame('شاورما دجاج', $public[0]['items'][0]['name']['ar']);
        $this->assertSame(27000, $public[0]['items'][0]['price']);
        $this->assertNull($public[0]['items'][0]['badge']);
        $this->assertSame($photoUrl, $public[0]['items'][0]['photo_url']);
        $this->assertArrayNotHasKey('is_active', $public[0]);
        $this->assertArrayNotHasKey('is_available', $public[0]['items'][0]);

        $this->deleteJson('/api/restaurant/menu/items/'.$itemId)
            ->assertOk()
            ->assertJsonPath('message', 'deleted');
        $this->deleteJson('/api/restaurant/menu/items/'.$menu[1]['items'][1]['id'])
            ->assertOk();
        $this->deleteJson('/api/restaurant/menu/categories/'.$categoryId)
            ->assertOk()
            ->assertJsonPath('message', 'deleted');

        $restaurant->update(['is_active' => false]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/restaurants/shawarma-house/menu')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
