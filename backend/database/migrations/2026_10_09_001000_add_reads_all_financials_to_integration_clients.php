<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin switch for a finance system: its analytics, subscription and statement reads cover every storefront.
        Schema::table('integration_clients', fn (Blueprint $table) => $table->boolean('reads_all_financials')->default(false)->after('is_active'));
    }

    public function down(): void
    {
        Schema::table('integration_clients', fn (Blueprint $table) => $table->dropColumn('reads_all_financials'));
    }
};
