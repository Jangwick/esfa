<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('system_code', 80)->unique();
            $table->string('contact_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('integration_clients')->cascadeOnDelete();
            $table->string('key', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at')->index();
            $table->unique(['client_id', 'key']);
        });
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('integration_clients')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->json('events');
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event_id', 80);
            $table->string('event_type', 80);
            $table->longText('request_body');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->timestampTz('claimed_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('attempt_log');
            $table->timestampsTz();
            $table->unique(['endpoint_id', 'event_id']);
        });
        Schema::create('scheduler_locks', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->uuid('owner')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_run_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['integration_clients', 'idempotency_records', 'webhook_endpoints', 'webhook_deliveries', 'scheduler_locks'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            }
        }
    }

    public function down(): void
    {
        foreach (['scheduler_locks', 'webhook_deliveries', 'webhook_endpoints', 'idempotency_records', 'integration_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
