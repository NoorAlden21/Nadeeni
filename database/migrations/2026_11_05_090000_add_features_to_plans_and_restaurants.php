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
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('pager')->default(true);
            $table->boolean('menu')->default(false);
            $table->boolean('ordering')->default(false);
            $table->boolean('kitchen')->default(false);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('pager')->default(false);
            $table->boolean('menu')->default(false);
            $table->boolean('ordering')->default(false);
            $table->boolean('kitchen')->default(false);
        });

        $plans = DB::table('subscription_plans')->get();

        foreach ($plans as $plan) {
            DB::table('restaurants')
                ->where('subscription_plan_id', $plan->id)
                ->update([
                    'pager' => $plan->pager,
                    'menu' => $plan->menu,
                    'ordering' => $plan->ordering,
                    'kitchen' => $plan->kitchen,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['pager', 'menu', 'ordering', 'kitchen']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['pager', 'menu', 'ordering', 'kitchen']);
        });
    }
};
