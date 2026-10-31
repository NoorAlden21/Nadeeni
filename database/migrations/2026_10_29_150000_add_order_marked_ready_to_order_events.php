<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_events DROP CONSTRAINT IF EXISTS order_events_event_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE order_events ADD CONSTRAINT order_events_event_type_check
            CHECK (event_type::text = ANY (ARRAY[
                'created',
                'customer_linked',
                'order_marked_ready',
                'notification_sent',
                'notification_failed',
                'notification_resent',
                'collected',
                'cancelled',
                'expired'
            ]::text[]))
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_events DROP CONSTRAINT IF EXISTS order_events_event_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE order_events ADD CONSTRAINT order_events_event_type_check
            CHECK (event_type::text = ANY (ARRAY[
                'created',
                'customer_linked',
                'notification_sent',
                'notification_failed',
                'notification_resent',
                'collected',
                'cancelled',
                'expired'
            ]::text[]))
        SQL);
    }
};
