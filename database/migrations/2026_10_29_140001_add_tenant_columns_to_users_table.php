<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
            $table->foreignId('restaurant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('restaurant_role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_role_shape_check CHECK (
                (is_platform_admin = true AND restaurant_id IS NULL AND restaurant_role IS NULL)
                OR
                (is_platform_admin = false AND restaurant_id IS NOT NULL AND restaurant_role IN ('cashier', 'owner'))
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_shape_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_id');
            $table->dropColumn(['is_platform_admin', 'restaurant_role', 'is_active']);
        });
    }
};
