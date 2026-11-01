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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('interval', ['month', 'year', 'trial']);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->unsignedInteger('price');
            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $table->timestampTz('subscription_starts_at')->nullable();
            $table->timestampTz('subscription_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
            $table->dropColumn(['subscription_starts_at', 'subscription_ends_at']);
        });

        Schema::dropIfExists('subscription_plans');
    }
};
