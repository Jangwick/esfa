<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scheduled work stamps a shipment when it alerts about it: once when overdue, and when stuck (cleared by a new event).
        Schema::table('shipments', fn (Blueprint $table) => $table->timestampTz('overdue_notified_at')->nullable()->after('cash_collected'));
        Schema::table('shipments', fn (Blueprint $table) => $table->timestampTz('stuck_notified_at')->nullable()->after('overdue_notified_at'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shipments ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn(['overdue_notified_at', 'stuck_notified_at']));
    }
};
