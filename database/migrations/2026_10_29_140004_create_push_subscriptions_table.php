<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->uuid('order_access_token_id');
            $table->foreign(['order_access_token_id', 'order_id'])
                ->references(['id', 'order_id'])
                ->on('order_access_tokens')
                ->cascadeOnDelete();
            $table->text('endpoint')->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('content_encoding')->default('aes128gcm');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
