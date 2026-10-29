<?php

namespace Database\Factories;

use App\Enums\PreferredQrMode;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'preferred_qr_mode' => PreferredQrMode::Shared,
            'timezone' => 'Asia/Damascus',
            'order_ttl_minutes' => 240,
            'is_active' => true,
        ];
    }
}
