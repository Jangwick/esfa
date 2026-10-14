<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The counts of the last completed scheduler run, with finished_at, for the Automation page.
        Schema::table('scheduler_locks', fn (Blueprint $table) => $table->json('last_result')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE scheduler_locks ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::table('scheduler_locks', fn (Blueprint $table) => $table->dropColumn('last_result'));
    }
};
