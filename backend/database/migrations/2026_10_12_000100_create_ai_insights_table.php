<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every Gemini answer SFA asked for: reused for the same input (input_hash), shown again on screen, counted against
        // the daily token limit, and kept as the record of what the AI said and who asked.
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 40);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 10);
            $table->string('model', 60);
            $table->char('input_hash', 64)->index();
            $table->json('output')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['subject_type', 'subject_id', 'feature']);
            $table->index('created_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_insights ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
