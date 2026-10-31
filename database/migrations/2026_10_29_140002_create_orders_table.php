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
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 32);
            $table->enum('status', ['waiting', 'ready', 'collected', 'cancelled', 'expired'])->default('waiting');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('visitor_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->index(['restaurant_id', 'status', 'created_at']);
            $table->index(['restaurant_id', 'created_at']);
            $table->index(['restaurant_id', 'visitor_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX orders_one_active_number_per_restaurant
            ON orders (restaurant_id, order_number)
            WHERE status IN ('waiting', 'ready')
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX orders_active_expires_at_idx
            ON orders (expires_at)
            WHERE status IN ('waiting', 'ready')
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
