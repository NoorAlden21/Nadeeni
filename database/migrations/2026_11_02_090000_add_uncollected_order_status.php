<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fresh databases already include this status. This updates a database
     * that was migrated before uncollected existed.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'uncollected_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestampTz('uncollected_at')->nullable();
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck('orders', 'orders_status_check', 'status', [
            'waiting',
            'ready',
            'collected',
            'cancelled',
            'expired',
            'uncollected',
        ]);
        $this->replaceCheck('order_events', 'order_events_event_type_check', 'event_type', [
            'created',
            'customer_linked',
            'order_marked_ready',
            'notification_sent',
            'notification_failed',
            'notification_resent',
            'collected',
            'cancelled',
            'expired',
            'uncollected',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'uncollected_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('uncollected_at');
            });
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function replaceCheck(string $table, string $constraint, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $value): string => "'".$value."'", $values));

        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS '.$constraint);
        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$constraint.' CHECK ('.$column."::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
