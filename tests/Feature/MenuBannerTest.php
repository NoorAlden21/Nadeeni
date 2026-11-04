<?php

namespace Tests\Feature;

use App\Models\MenuBanner;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_manage_banners_and_the_public_menu_shows_the_active_ones(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shawarma-house']);
        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $other = User::factory()->create();

        $this->actAs($cashier);

        $created = $this->post('/api/restaurant/banners', [
            'sort_order' => 2,
            'image' => UploadedFile::fake()->image('offer.png', 80, 40),
        ], ['Accept' => 'application/json']);

        $created->assertCreated()
            ->assertJsonPath('sort_order', 2)
            ->assertJsonPath('is_active', true);

        $id = $created->json('id');
        $imageUrl = $created->json('image_url');
        $this->assertIsString($imageUrl);
        $this->assertStringContainsString('/api/public/restaurants/shawarma-house/banners/'.$id.'/image?v=', $imageUrl);

        $this->get('/api/public/restaurants/shawarma-house/banners/'.$id.'/image')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $hidden = $this->post('/api/restaurant/banners', [
            'sort_order' => 1,
            'is_active' => 0,
            'image' => UploadedFile::fake()->image('old.jpg', 20, 20),
        ], ['Accept' => 'application/json'])->assertCreated();

        $replaced = $this->patch('/api/restaurant/banners/'.$id, [
            'sort_order' => 0,
            'image' => UploadedFile::fake()->image('next.webp', 20, 20),
        ], ['Accept' => 'application/json']);

        $replaced->assertOk()->assertJsonPath('sort_order', 0);
        $this->assertNotSame($imageUrl, $replaced->json('image_url'));

        $list = $this->getJson('/api/restaurant/banners')->assertOk()->json();
        $this->assertSame($id, $list[0]['id']);
        $this->assertSame($hidden->json('id'), $list[1]['id']);
        $this->assertFalse($list[1]['is_active']);

        $public = $this->getJson('/api/public/restaurants/shawarma-house/menu')->assertOk()->json();
        $this->assertCount(1, $public['banners']);
        $this->assertSame($replaced->json('image_url'), $public['banners'][0]['image_url']);
        $this->assertSame([], $public['categories']);

        $this->post('/api/restaurant/banners', [
            'image' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        $this->actAs($other);
        $this->patchJson('/api/restaurant/banners/'.$id, ['sort_order' => 3])->assertNotFound();

        $this->actAs($cashier);

        foreach (range(1, 3) as $ignored) {
            $this->post('/api/restaurant/banners', [
                'image' => UploadedFile::fake()->image('more.png', 10, 10),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->post('/api/restaurant/banners', [
            'image' => UploadedFile::fake()->image('sixth.png', 10, 10),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'banner_limit');

        $this->assertSame(MenuBanner::LIMIT, MenuBanner::query()->count());

        $this->deleteJson('/api/restaurant/banners/'.$id)
            ->assertOk()
            ->assertJsonPath('message', 'deleted');

        $this->get('/api/public/restaurants/shawarma-house/banners/'.$id.'/image')->assertNotFound();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }
}
