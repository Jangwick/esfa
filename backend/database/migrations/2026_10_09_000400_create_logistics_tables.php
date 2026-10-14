<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) { $table->id(); $table->string('code', 10)->unique(); $table->string('name'); $table->timestampsTz(); });
        Schema::create('provinces', function (Blueprint $table) { $table->id(); $table->string('code', 10)->unique(); $table->string('name'); $table->foreignId('region_id')->constrained()->restrictOnDelete(); $table->timestampsTz(); });
        Schema::create('cities', function (Blueprint $table) { $table->id(); $table->string('code', 10)->unique(); $table->string('name'); $table->string('type', 20)->default('city'); $table->foreignId('region_id')->constrained()->restrictOnDelete(); $table->foreignId('province_id')->nullable()->constrained()->restrictOnDelete(); $table->timestampsTz(); });
        Schema::create('barangays', function (Blueprint $table) { $table->id(); $table->string('code', 10)->unique(); $table->string('name'); $table->foreignId('city_id')->constrained()->restrictOnDelete(); $table->timestampsTz(); });
        Schema::create('addresses', function (Blueprint $table) {
            $table->id(); $table->string('line1'); $table->string('line2')->nullable();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete(); $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->constrained()->restrictOnDelete(); $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('postal_code', 20); $table->decimal('latitude', 10, 7)->nullable(); $table->decimal('longitude', 10, 7)->nullable();
            $table->string('contact_name'); $table->string('contact_phone', 30); $table->timestampsTz();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('code', 80)->unique(); $table->string('type', 30);
            $table->foreignId('address_id')->constrained()->restrictOnDelete(); $table->foreignId('seller_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true); $table->timestampsTz();
        });
        Schema::create('couriers', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('code', 80)->unique(); $table->json('service_types');
            $table->string('tracking_url_template', 2048)->nullable(); $table->text('webhook_secret'); $table->unsignedInteger('volumetric_divisor')->nullable();
            $table->boolean('is_active')->default(true); $table->timestampsTz();
        });
        Schema::create('zones', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedSmallInteger('min_days'); $table->unsignedSmallInteger('max_days');
            $table->json('province_ids'); $table->json('city_ids'); $table->boolean('is_active')->default(true); $table->timestampsTz();
        });
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id(); $table->foreignId('courier_id')->constrained()->restrictOnDelete(); $table->string('service_type', 30);
            $table->foreignId('origin_zone_id')->constrained('zones')->restrictOnDelete(); $table->foreignId('destination_zone_id')->constrained('zones')->restrictOnDelete();
            $table->unsignedInteger('base_weight_grams'); $table->bigInteger('base_fee_amount'); $table->bigInteger('additional_per_500g_amount');
            $table->unsignedInteger('cod_fee_bp')->default(0); $table->unsignedInteger('max_weight_grams'); $table->string('currency', 3)->default('PHP');
            $table->boolean('is_active')->default(true); $table->timestampsTz();
            $table->unique(['courier_id', 'service_type', 'origin_zone_id', 'destination_zone_id'], 'shipping_rate_route_unique');
        });
        Schema::create('shipments', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete(); $table->foreignId('courier_id')->constrained()->restrictOnDelete(); $table->string('service_type', 30);
            $table->string('tracking_number', 100)->nullable(); $table->foreignId('origin_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_address_id')->constrained('addresses')->restrictOnDelete(); $table->unsignedInteger('weight_grams'); $table->json('dimensions');
            $table->bigInteger('shipping_fee_amount'); $table->bigInteger('cod_amount')->default(0); $table->string('currency', 3)->default('PHP');
            $table->string('status', 30)->default('pending')->index(); $table->date('promised_from'); $table->date('promised_to'); $table->timestampTz('delivered_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0); $table->string('recipient_name')->nullable(); $table->foreignId('proof_attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->text('delivery_note')->nullable(); $table->boolean('cash_collected')->default(false); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestampsTz();
            $table->unique(['order_id', 'seller_id']); $table->unique(['courier_id', 'tracking_number']);
        });
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('shipment_id')->constrained()->cascadeOnDelete(); $table->string('status', 30); $table->string('location')->nullable();
            $table->text('note')->nullable(); $table->timestampTz('occurred_at'); $table->string('source', 30); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestampsTz();
        });
        Schema::create('courier_webhook_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('courier_id')->constrained()->cascadeOnDelete(); $table->string('event_id', 255); $table->foreignId('shipment_id')->constrained()->cascadeOnDelete(); $table->timestampsTz(); $table->unique(['courier_id', 'event_id']);
        });
        if (DB::getDriverName() === 'pgsql') { foreach (['regions', 'provinces', 'cities', 'barangays', 'addresses', 'warehouses', 'couriers', 'zones', 'shipping_rates', 'shipments', 'shipment_events', 'courier_webhook_events'] as $table) { DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY"); } }
    }

    public function down(): void
    {
        foreach (['courier_webhook_events', 'shipment_events', 'shipments', 'shipping_rates', 'zones', 'couriers', 'warehouses', 'addresses', 'barangays', 'cities', 'provinces', 'regions'] as $table) { Schema::dropIfExists($table); }
    }
};
