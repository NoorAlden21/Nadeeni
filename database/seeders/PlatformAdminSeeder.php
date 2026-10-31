<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('nadeeni.admin.email');
        $password = config('nadeeni.admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD before seeding the platform admin.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('nadeeni.admin.name'),
                'password' => $password,
                'is_platform_admin' => true,
                'restaurant_id' => null,
                'restaurant_role' => null,
                'is_active' => true,
            ],
        );
    }
}
