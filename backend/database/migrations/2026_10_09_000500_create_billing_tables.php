<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('code')->unique();
            $table->bigInteger('monthly_price_amount'); $table->bigInteger('annual_price_amount');
            $table->unsignedInteger('trial_days')->default(0); $table->json('features'); $table->json('limits');
            $table->unsignedInteger('commission_rate_bp')->nullable(); $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); $table->timestampsTz();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id(); $table->foreignId('seller_id')->constrained()->restrictOnDelete(); $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('next_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->string('billing_cycle'); $table->string('status')->index(); $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start'); $table->timestampTz('current_period_end')->index();
            $table->boolean('cancel_at_period_end')->default(false); $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0); $table->timestampTz('next_retry_at')->nullable()->index();
            $table->timestampsTz();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete(); $table->timestampTz('period_start'); $table->timestampTz('period_end');
            $table->string('kind')->default('renewal'); $table->json('line_items'); $table->bigInteger('subtotal_amount');
            $table->bigInteger('total_amount'); $table->string('currency',3)->default('PHP'); $table->string('status')->index();
            $table->date('due_date'); $table->timestampTz('paid_at')->nullable(); $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz(); $table->unique(['subscription_id','period_start','kind']);
        });
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id(); $table->string('scope'); $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedInteger('rate_bp'); $table->bigInteger('fixed_fee_amount')->default(0);
            $table->timestampTz('effective_from'); $table->timestampTz('effective_to')->nullable(); $table->timestampsTz();
            $table->index(['scope','scope_id','effective_from']);
        });
        Schema::create('settlements', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->date('period_start'); $table->date('period_end');
            foreach (['gross_sales','refunds','commission','gateway_fees','subscription_fees','adjustments','net_payable'] as $amount) { $table->bigInteger($amount.'_amount')->default(0); }
            $table->string('currency',3)->default('PHP'); $table->string('status')->default('draft')->index();
            $table->timestampTz('finalized_at')->nullable(); $table->timestampTz('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestampsTz();
            $table->unique(['seller_id','period_start','period_end']);
        });
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_item_id')->constrained()->restrictOnDelete(); $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete(); $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('commission_rules')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained()->restrictOnDelete(); $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('entry_type')->default('capture'); $table->bigInteger('gross_amount'); $table->unsignedInteger('rate_bp');
            $table->bigInteger('fixed_fee_amount'); $table->bigInteger('commission_amount'); $table->bigInteger('seller_net_amount');
            $table->timestampsTz(); $table->unique(['payment_id','order_item_id','refund_id']);
        });
        Schema::create('payouts', function (Blueprint $table) {
            $table->id(); $table->string('reference')->nullable()->unique(); $table->foreignId('settlement_id')->constrained()->restrictOnDelete();
            $table->string('method'); $table->string('destination_masked'); $table->bigInteger('amount');
            $table->string('currency',3)->default('PHP'); $table->string('status')->default('pending');
            $table->timestampTz('paid_at')->nullable(); $table->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['plans','subscriptions','invoices','commission_rules','settlements','commission_entries','payouts'] as $table) DB::statement('ALTER TABLE '.$table.' ENABLE ROW LEVEL SECURITY');
        }
    }
    public function down(): void
    {
        foreach (['payouts','commission_entries','settlements','commission_rules','invoices','subscriptions','plans'] as $table) Schema::dropIfExists($table);
    }
};
