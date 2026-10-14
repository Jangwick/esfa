<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BIL-12: the settlement that netted this negative (finalized) balance from the seller's next payout.
        Schema::table('settlements', fn (Blueprint $table) => $table->unsignedBigInteger('recovered_by_settlement_id')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            // Only on PostgreSQL: on SQLite a foreign key rebuilds the table, which would turn the partial
            // settlements_one_open_per_seller_period index into a plain unique index.
            Schema::table('settlements', fn (Blueprint $table) => $table->foreign('recovered_by_settlement_id')->references('id')->on('settlements')->restrictOnDelete());
            DB::statement('ALTER TABLE settlements ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('settlements', fn (Blueprint $table) => $table->dropForeign(['recovered_by_settlement_id']));
        }
        Schema::table('settlements', fn (Blueprint $table) => $table->dropColumn('recovered_by_settlement_id'));
    }
};
