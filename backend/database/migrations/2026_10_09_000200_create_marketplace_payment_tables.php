<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'sellers', 'categories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id(); $table->string('source_system', 80); $table->string('external_id');
                if ($name === 'categories') {
                    $table->string('name'); $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
                } elseif ($name === 'customers') {
                    $table->string('name'); $table->string('email')->nullable(); $table->string('phone', 80)->nullable(); $table->json('default_address')->nullable();
                } else {
                    $table->string('shop_name'); $table->string('owner_name')->nullable(); $table->string('email')->nullable(); $table->string('phone', 80)->nullable();
                    $table->text('payout_destination')->nullable(); $table->json('pickup_address')->nullable();
                }
                $table->timestampsTz(); $table->unique(['source_system', 'external_id']);
            });
        }
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); $table->string('source_system', 80); $table->string('external_id');
            $table->foreignId('customer_id')->constrained('customers'); $table->string('status_snapshot', 80)->default('placed');
            foreach (['subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount'] as $column) { $table->bigInteger($column)->default(0); }
            $table->string('currency', 3)->default('PHP'); $table->timestampTz('placed_at'); $table->timestampsTz(); $table->unique(['source_system', 'external_id']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->constrained()->cascadeOnDelete(); $table->foreignId('seller_id')->constrained();
            $table->foreignId('category_id')->nullable()->constrained(); $table->string('product_name'); $table->string('sku')->nullable();
            $table->unsignedInteger('quantity'); $table->bigInteger('unit_price_amount'); $table->bigInteger('line_total_amount'); $table->timestampsTz();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('customer_id')->nullable()->constrained(); $table->foreignId('seller_id')->nullable()->constrained(); $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('method', 40); $table->string('gateway', 80); $table->string('gateway_reference')->nullable()->index();
            foreach (['total_amount', 'captured_amount', 'refunded_amount', 'fee_amount'] as $column) { $table->bigInteger($column)->default(0); }
            $table->string('currency', 3)->default('PHP'); $table->string('status', 40)->default('pending')->index(); $table->text('failure_reason')->nullable();
            $table->string('risk_level', 20)->default('none')->index();
            foreach (['authorized_at', 'captured_at', 'failed_at', 'expires_at'] as $column) { $table->timestampTz($column)->nullable(); }
            $table->timestampsTz();
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('payment_id')->constrained(); $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->bigInteger('total_amount'); $table->string('currency', 3)->default('PHP'); $table->string('reason', 80); $table->text('notes')->nullable();
            $table->string('status', 30)->default('requested')->index(); $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('client_id')->nullable()->constrained('integration_clients'); $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable(); $table->text('rejection_reason')->nullable(); $table->string('gateway_reference')->nullable();
            $table->timestampTz('completed_at')->nullable(); $table->timestampsTz();
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id(); $table->uuid('event_id')->index(); $table->string('entry_type', 50); $table->string('direction', 10); $table->string('account', 50);
            $table->bigInteger('total_amount'); $table->string('currency', 3)->default('PHP'); $table->foreignId('payment_id')->nullable()->constrained();
            $table->foreignId('refund_id')->nullable()->constrained(); $table->unsignedBigInteger('settlement_id')->nullable()->index(); $table->unsignedBigInteger('payout_id')->nullable()->index();
            $table->text('memo'); $table->foreignId('created_by')->nullable()->constrained('users'); $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id(); $table->string('filename'); $table->string('mime_type', 80); $table->unsignedInteger('size'); $table->longText('content');
            $table->date('date_from'); $table->date('date_to'); $table->foreignId('created_by')->constrained('users'); $table->timestampsTz();
        });
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('reconciliation_id')->constrained()->cascadeOnDelete(); $table->foreignId('payment_id')->nullable()->constrained();
            $table->date('date'); $table->string('gateway_reference'); $table->bigInteger('total_amount'); $table->bigInteger('fee_amount')->default(0);
            $table->string('status', 40)->index(); $table->text('resolution_notes')->nullable(); $table->foreignId('resolved_by')->nullable()->constrained('users'); $table->timestampTz('resolved_at')->nullable(); $table->timestampsTz();
        });
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id(); $table->string('gateway', 80); $table->string('event_id'); $table->json('payload'); $table->timestampTz('created_at')->useCurrent(); $table->unique(['gateway', 'event_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['customers','sellers','categories','orders','order_items','payments','refunds','ledger_entries','reconciliations','reconciliation_items','gateway_events'] as $name) { DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY"); }
        }
    }

    public function down(): void
    {
        foreach (['gateway_events','reconciliation_items','reconciliations','ledger_entries','refunds','payments','order_items','orders','categories','sellers','customers'] as $name) { Schema::dropIfExists($name); }
    }
};
