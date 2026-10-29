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
        Schema::create('order_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->unique(['id', 'order_id']);
            $table->string('token', 64)->unique();
            $table->enum('purpose', ['cashier_qr', 'shared_registration']);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_access_tokens_one_cashier_qr
            ON order_access_tokens (order_id)
            WHERE purpose = 'cashier_qr' AND revoked_at IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_access_tokens');
    }
};
