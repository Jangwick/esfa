<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['sla_policies', 'tickets', 'ticket_messages', 'attachments', 'kb_categories', 'kb_articles', 'canned_responses'];

    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id(); $table->string('priority')->unique(); $table->unsignedInteger('first_response_hours'); $table->unsignedInteger('resolution_hours'); $table->timestampsTz();
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->string('subject'); $table->text('description');
            $table->string('source_system'); $table->string('channel'); $table->string('category'); $table->string('priority')->index(); $table->string('status')->default('open')->index();
            $table->string('requester_type'); $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('order_id')->nullable(); $table->unsignedBigInteger('payment_id')->nullable(); $table->unsignedBigInteger('shipment_id')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); $table->json('tags')->nullable();
            $table->timestampTz('first_response_due_at'); $table->timestampTz('resolution_due_at'); $table->timestampTz('first_responded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable(); $table->timestampTz('closed_at')->nullable(); $table->timestampTz('resolution_paused_at')->nullable(); $table->unsignedInteger('resolution_paused_seconds')->default(0);
            $table->unsignedTinyInteger('csat_score')->nullable(); $table->text('csat_comment')->nullable();
            $table->timestampTz('sla_at_risk_notified_at')->nullable(); $table->timestampTz('sla_breached_notified_at')->nullable();
            $table->timestampsTz(); $table->index(['source_system', 'requester_type', 'requester_id']);
        });
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id(); $table->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $table->string('author_type'); $table->unsignedBigInteger('author_id')->nullable();
            $table->text('body'); $table->boolean('is_internal_note')->default(false); $table->timestampsTz();
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id(); $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete(); $table->foreignId('ticket_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->nullableMorphs('attachable'); $table->string('filename'); $table->string('mime_type'); $table->unsignedInteger('size'); $table->longText('content');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestampsTz();
        });
        Schema::create('kb_categories', function (Blueprint $table) { $table->id(); $table->string('name'); $table->integer('sort_order')->default(0); $table->timestampsTz(); });
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->string('slug')->unique(); $table->text('body'); $table->foreignId('category_id')->nullable()->constrained('kb_categories')->nullOnDelete();
            $table->string('status')->default('draft'); $table->timestampTz('published_at')->nullable(); $table->unsignedInteger('helpful_count')->default(0); $table->unsignedInteger('not_helpful_count')->default(0); $table->timestampsTz();
        });
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->text('body'); $table->boolean('is_shared')->default(true); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') { foreach ($this->tables as $table) { DB::statement('ALTER TABLE "'.$table.'" ENABLE ROW LEVEL SECURITY'); } }
    }

    public function down(): void { foreach (array_reverse($this->tables) as $table) { Schema::dropIfExists($table); } }
};
