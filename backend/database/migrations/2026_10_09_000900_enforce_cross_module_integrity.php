<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $links = [
        ['payments', 'invoice_id', 'invoices'],
        ['refunds', 'ticket_id', 'tickets'],
        ['tickets', 'order_id', 'orders'],
        ['tickets', 'payment_id', 'payments'],
        ['tickets', 'shipment_id', 'shipments'],
        ['ledger_entries', 'settlement_id', 'settlements'],
        ['ledger_entries', 'payout_id', 'payouts'],
        ['commission_entries', 'reversal_of_id', 'commission_entries'],
    ];

    public function up(): void
    {
        foreach ($this->links as [$table, $column, $target]) {
            Schema::table($table, fn (Blueprint $schema) => $schema->foreign($column)->references('id')->on($target)->restrictOnDelete());
        }
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_current_per_seller ON subscriptions(seller_id) WHERE status IN ('trialing', 'active', 'past_due')");
        DB::statement("CREATE UNIQUE INDEX commission_capture_per_payment_item ON commission_entries(payment_id, order_item_id) WHERE entry_type = 'capture' AND refund_id IS NULL");
        DB::statement('CREATE UNIQUE INDEX payments_one_capture_per_order ON payments(order_id) WHERE order_id IS NOT NULL AND captured_amount > 0');
        DB::statement("CREATE UNIQUE INDEX payments_one_capture_per_invoice ON payments(invoice_id) WHERE invoice_id IS NOT NULL AND (captured_amount > 0 OR status = 'captured')");
        if (DB::getDriverName() === 'pgsql') {
            // Laravel creates this table before the application migrations run.
            DB::statement('ALTER TABLE migrations ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        foreach (['payments_one_capture_per_invoice', 'payments_one_capture_per_order', 'commission_capture_per_payment_item', 'subscriptions_one_current_per_seller'] as $index) {
            DB::statement('DROP INDEX IF EXISTS '.$index);
        }
        foreach (array_reverse($this->links) as [$table, $column]) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropForeign([$column]));
        }
    }
};
