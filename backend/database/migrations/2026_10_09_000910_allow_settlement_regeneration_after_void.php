<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', fn (Blueprint $table) => $table->dropUnique(['seller_id', 'period_start', 'period_end']));
        // One open settlement per seller and period. A voided settlement releases its entries,
        // so the same period can be generated again (partial index: SQLite and PostgreSQL).
        DB::statement("CREATE UNIQUE INDEX settlements_one_open_per_seller_period ON settlements(seller_id, period_start, period_end) WHERE status <> 'void'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS settlements_one_open_per_seller_period');
        Schema::table('settlements', fn (Blueprint $table) => $table->unique(['seller_id', 'period_start', 'period_end']));
    }
};