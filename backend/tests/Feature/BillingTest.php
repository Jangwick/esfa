<?php

namespace Tests\Feature;

use App\Gateways\GatewayEvent;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\BillingService;
use App\Services\CommissionService;
use App\Services\PaymentService;
use App\Services\Scheduler;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $finance;
    private CommissionRule $global;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->global = CommissionRule::create(['scope' => 'global', 'rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subYear()]);
        $this->admin = User::create(['name' => 'Billing admin', 'email' => 'billing.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $this->admin->assignRole('Admin');
        $this->finance = User::create(['name' => 'Billing finance', 'email' => 'billing.finance@example.com', 'password' => 'password', 'is_active' => true]);
        $this->finance->assignRole('Accounting Staff');
    }

    private function asFinance(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->finance->createToken('staff', ['staff'])->plainTextToken);
    }

    private function plan(int $monthly, int $annual = 0, int $trial = 0): Plan
    {
        return Plan::create(['name' => 'Plan '.$monthly, 'code' => 'plan-'.Str::random(8), 'monthly_price_amount' => $monthly, 'annual_price_amount' => $annual, 'trial_days' => $trial, 'features' => [], 'limits' => [], 'is_active' => true]);
    }

    private function seller(): Seller
    {
        return Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.Str::random(8), 'shop_name' => 'Billing shop', 'payout_destination' => ['account_number' => '000012345678']]);
    }

    private function subscribe(Plan $plan, string $cycle = 'monthly', ?Seller $seller = null): Subscription
    {
        return app(BillingService::class)->subscribe(['seller_id' => ($seller ?? $this->seller())->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle]);
    }

    /** @param array<int, array{0: Seller, 1: int, 2?: ?Category}> $lines */
    private function paidOrder(array $lines): Payment
    {
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-'.Str::random(8), 'name' => 'Billing customer']);
        $total = array_sum(array_column($lines, 1));
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-'.Str::random(8), 'customer_id' => $customer->id, 'subtotal_amount' => $total, 'total_amount' => $total, 'placed_at' => now()]);
        foreach ($lines as $line) {
            $order->items()->create(['seller_id' => $line[0]->id, 'category_id' => ($line[2] ?? null)?->id, 'product_name' => 'Billing item', 'quantity' => 1, 'unit_price_amount' => $line[1], 'line_total_amount' => $line[1]]);
        }
        $payment = app(PaymentService::class)->create($order, 'card')['payment'];
        $this->assertSame('captured', $payment->status);

        return $payment;
    }

    private function assertLedgerBalanced(): void
    {
        $this->assertSame((int) LedgerEntry::where('direction', 'debit')->sum('total_amount'), (int) LedgerEntry::where('direction', 'credit')->sum('total_amount'));
    }

    public function test_trial_starts_without_a_charge_and_converts_once_when_it_ends(): void
    {
        $subscription = $this->subscribe($this->plan(199900, 0, 14));
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame(0, Invoice::count());
        $this->travel(15)->days();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['subscriptions_renewed']);
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame(['renewal' => 'paid'], Invoice::pluck('status', 'kind')->all());
        $this->assertSame(0, app(BillingService::class)->runScheduled()['subscriptions_renewed']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_paid_plans_charge_immediately_and_renew_exactly_once_per_period(): void
    {
        $subscription = $this->subscribe($this->plan(199900));
        $this->assertSame('active', $subscription->status);
        $invoice = Invoice::firstOrFail();
        $this->assertSame(['paid', 'captured'], [$invoice->status, $invoice->payment->status]);
        $this->assertSame(2, LedgerEntry::where('entry_type', 'subscription_charge')->where('total_amount', 199900)->count());
        $this->travelTo($subscription->current_period_end->addMinute());
        $this->assertSame(1, app(BillingService::class)->runScheduled()['subscriptions_renewed']);
        $this->assertSame(0, app(BillingService::class)->runScheduled()['subscriptions_renewed']);
        $this->assertSame(2, Invoice::where('status', 'paid')->count());
        $this->assertSame(2, Payment::whereNotNull('invoice_id')->where('status', 'captured')->count());
        $this->assertLedgerBalanced();
    }

    public function test_failed_charges_retry_after_one_three_and_seven_days_then_cancel_without_recharging(): void
    {
        $subscription = $this->subscribe($this->plan(10099)); // Sandbox declines amounts ending in 99.
        $invoice = Invoice::firstOrFail();
        $this->assertSame(['past_due', 1], [$subscription->status, $subscription->retry_count]);
        foreach ([1, 3, 7] as $days) {
            $subscription->refresh();
            $this->assertSame(CarbonImmutable::parse($invoice->due_date)->addDays($days)->toDateString(), $subscription->next_retry_at->toDateString());
            $this->travelTo($subscription->next_retry_at);
            $this->assertSame(1, app(BillingService::class)->runScheduled()['billing_retries']);
        }
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertSame('uncollectible', $invoice->fresh()->status);
        $this->assertSame(1, Notification::where('user_id', $this->finance->id)->where('type', 'subscription_cancelled_unpaid')->count(), 'Finance hears about the dunning cancel once.');
        $this->assertSame(4, Payment::where('invoice_id', $invoice->id)->count());
        $this->travel(30)->days();
        app(BillingService::class)->runScheduled();
        $this->assertSame(4, Payment::where('invoice_id', $invoice->id)->count(), 'Repeated scheduler runs never charge a closed invoice again.');
    }

    public function test_cancel_and_resume_follow_the_state_machine(): void
    {
        $active = $this->subscribe($this->plan(199900));
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/cancel")->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('cancel_at_period_end', true);
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/resume")->assertOk()->assertJsonPath('cancel_at_period_end', false);
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/cancel", ['immediate' => true])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/cancel")->assertUnprocessable();
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/change-plan", ['plan_id' => $this->plan(299900)->id])->assertUnprocessable();
        $this->asFinance()->postJson("/api/v1/subscriptions/{$active->id}/resume")->assertOk()->assertJsonPath('status', 'active');

        $pastDue = $this->subscribe($this->plan(10099));
        $this->asFinance()->postJson("/api/v1/subscriptions/{$pastDue->id}/cancel")->assertOk()->assertJsonPath('cancel_at_period_end', true);
        $this->asFinance()->postJson("/api/v1/subscriptions/{$pastDue->id}/resume")->assertOk()->assertJsonPath('status', 'past_due');

        $expired = Subscription::create(['seller_id' => $this->seller()->id, 'plan_id' => $this->plan(100)->id, 'billing_cycle' => 'monthly', 'status' => 'expired', 'current_period_start' => now()->subMonths(2), 'current_period_end' => now()->subMonth()]);
        $this->asFinance()->postJson("/api/v1/subscriptions/{$expired->id}/resume")->assertUnprocessable();
        $this->asFinance()->postJson("/api/v1/subscriptions/{$expired->id}/cancel")->assertUnprocessable();
    }

    public function test_upgrades_prorate_business_calendar_days_half_up_and_downgrades_wait_for_the_period_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Manila'));
        [$growth, $pro] = [$this->plan(199900), $this->plan(499900)];
        $subscription = $this->subscribe($growth);
        $this->assertSame('2026-11-01 00:00', $subscription->current_period_end->timezone('Asia/Manila')->format('Y-m-d H:i'));
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:30:00', 'Asia/Manila'));
        // (499,900 − 199,900) × 21 remaining days ÷ 31 days = 203,225.81 → 203,226 centavos.
        $this->asFinance()->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $pro->id])->assertOk()->assertJsonPath('plan_id', $pro->id);
        $upgrade = Invoice::where('kind', 'upgrade')->firstOrFail();
        $this->assertSame([203226, 'paid'], [$upgrade->total_amount, $upgrade->status]);

        $this->asFinance()->postJson("/api/v1/subscriptions/{$subscription->id}/change-plan", ['plan_id' => $growth->id])->assertOk()->assertJsonPath('plan_id', $pro->id)->assertJsonPath('next_plan_id', $growth->id);
        $this->assertSame(2, Invoice::count(), 'A downgrade does not charge or refund.');
        $this->travelTo($subscription->fresh()->current_period_end->addMinute());
        app(BillingService::class)->runScheduled();
        $this->assertSame($growth->id, $subscription->fresh()->plan_id);
        $this->assertSame(199900, Invoice::where('kind', 'renewal')->value('total_amount'));
    }

    public function test_an_invoice_is_never_charged_twice_or_while_a_payment_awaits_the_gateway(): void
    {
        $this->subscribe($this->plan(199900));
        $paid = Invoice::firstOrFail();
        $this->asFinance()->postJson("/api/v1/invoices/{$paid->id}/charge")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame(1, Payment::where('invoice_id', $paid->id)->count());

        $pending = $this->subscribe($this->plan(10098)); // Sandbox keeps amounts ending in 98 pending.
        $invoice = Invoice::where('subscription_id', $pending->id)->firstOrFail();
        $this->asFinance()->postJson("/api/v1/invoices/{$invoice->id}/charge")->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->travelTo($pending->fresh()->next_retry_at);
        $this->assertSame(0, app(BillingService::class)->runScheduled()['billing_retries']);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        $open = Invoice::where('subscription_id', $this->subscribe($this->plan(20099))->id)->firstOrFail();
        $this->asFinance()->postJson("/api/v1/invoices/{$open->id}/void")->assertOk()->assertJsonPath('status', 'void');
        $this->asFinance()->postJson("/api/v1/invoices/{$open->id}/charge")->assertUnprocessable();
    }

    public function test_settlements_generate_idempotently_finalize_once_void_and_regenerate_and_pay_once_to_a_masked_destination(): void
    {
        $seller = $this->seller();
        $this->paidOrder([[$seller, 10000]])->update(['fee_amount' => 300]);
        $period = ['period_start' => now('Asia/Manila')->toDateString(), 'period_end' => now('Asia/Manila')->toDateString(), 'seller_id' => $seller->id];
        $first = $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()
            ->assertJsonPath('data.0.gateway_fees_amount', 300)->assertJsonPath('data.0.net_payable_amount', 9200)->json('data.0.id');
        $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()->assertJsonPath('data.0.id', $first);
        $this->asFinance()->postJson("/api/v1/settlements/{$first}/void")->assertOk()->assertJsonPath('status', 'void');
        $this->assertSame(0, CommissionEntry::where('settlement_id', $first)->count(), 'Voiding releases the entries.');
        $second = $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()->assertJsonPath('data.0.net_payable_amount', 9200)->json('data.0.id');
        $this->assertNotSame($first, $second);

        $this->asFinance()->postJson("/api/v1/settlements/{$second}/finalize")->assertOk()->assertJsonPath('status', 'finalized');
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/finalize")->assertUnprocessable();
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/void")->assertUnprocessable();
        $this->assertSame([300, 300], LedgerEntry::where('entry_type', 'gateway_fee')->pluck('total_amount')->all());
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/pay", ['method' => 'bank_transfer', 'destination_masked' => '000012345678'])->assertUnprocessable()->assertJsonValidationErrors('destination_masked');
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/pay", ['method' => 'gcash', 'destination_masked' => '•••• 5678'])->assertOk()
            ->assertJsonPath('status', 'paid')->assertJsonPath('payouts.0.amount', 9200)->assertJsonPath('payouts.0.destination_masked', '•••• 5678');
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/pay", ['method' => 'gcash'])->assertUnprocessable();
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/void")->assertUnprocessable();
        $this->assertSame(2, LedgerEntry::where('entry_type', 'gateway_fee')->count(), 'Gateway fees are posted exactly once.');
        $this->assertLedgerBalanced();
    }

    public function test_cancelling_closes_leftover_invoices_and_an_ended_subscription_cannot_be_charged(): void
    {
        $pastDue = $this->subscribe($this->plan(10099));
        $writtenOff = Invoice::where('subscription_id', $pastDue->id)->firstOrFail();
        $this->asFinance()->postJson("/api/v1/subscriptions/{$pastDue->id}/cancel", ['immediate' => true])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('uncollectible', $writtenOff->fresh()->status, 'Past-due debt is written off.');
        $this->asFinance()->postJson("/api/v1/invoices/{$writtenOff->id}/charge")->assertUnprocessable();

        $waiting = $this->subscribe($this->plan(10098)); // Sandbox keeps amounts ending in 98 pending.
        $inFlight = Invoice::where('subscription_id', $waiting->id)->firstOrFail();
        $lapsed = $this->subscribe($this->plan(20098));
        $voided = Invoice::where('subscription_id', $lapsed->id)->firstOrFail();
        Payment::where('invoice_id', $voided->id)->update(['status' => 'expired']);
        foreach ([$waiting, $lapsed] as $subscription) {
            $this->asFinance()->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediate' => true])->assertOk();
        }
        $this->assertSame('open', $inFlight->fresh()->status, 'An invoice whose payment is still with the gateway is left for that payment.');
        $this->assertSame('void', $voided->fresh()->status, 'A current subscription has nothing to write off, so its open invoice is voided.');
        $this->asFinance()->postJson("/api/v1/invoices/{$inFlight->id}/charge")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('cancelled', $waiting->fresh()->status, 'A charge never reactivates a cancelled subscription.');

        $paid = $this->subscribe($this->plan(199900));
        $this->asFinance()->postJson("/api/v1/subscriptions/{$paid->id}/cancel", ['immediate' => true])->assertOk();
        $invoice = Invoice::where('subscription_id', $paid->id)->firstOrFail();
        $this->asFinance()->postJson("/api/v1/invoices/{$invoice->id}/charge")->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_a_charge_captured_later_pays_its_invoice_in_the_same_run_without_reviving_a_cancelled_subscription(): void
    {
        $current = $this->subscribe($this->plan(10098)); // Sandbox keeps amounts ending in 98 pending.
        $cancelled = $this->subscribe($this->plan(20098));
        app(BillingService::class)->cancel($cancelled, true);
        foreach (Payment::whereNotNull('invoice_id')->get() as $payment) {
            app(PaymentService::class)->processEvent('sandbox', new GatewayEvent('evt-'.$payment->id, 'payment.authorized', $payment->reference, null), []);
        }
        config(['sfa.scheduler_enabled' => true]);

        $result = app(Scheduler::class)->run();
        $this->assertSame([2, 2], [$result['payments_auto_captured'], $result['invoices_reconciled']]);
        foreach ([$current, $cancelled] as $subscription) {
            $invoice = Invoice::where('subscription_id', $subscription->id)->sole();
            $this->assertSame(['paid', Payment::where('invoice_id', $invoice->id)->sole()->id], [$invoice->status, $invoice->payment_id]);
        }
        $this->assertSame(['active', 0], [$current->fresh()->status, $current->fresh()->retry_count]);
        $this->assertSame('cancelled', $cancelled->fresh()->status, 'A late payment never reactivates a cancelled subscription.');
        $alerts = fn () => Notification::where('type', 'subscription_payment_after_cancel')->orderBy('user_id');
        $this->assertSame([$this->admin->id, $this->finance->id], $alerts()->pluck('user_id')->all());
        $this->assertSame($cancelled->id, $alerts()->first()->data['subscription_id']);
        $this->assertLedgerBalanced();

        $again = app(Scheduler::class)->run();
        $this->assertSame([0, 0], [$again['payments_auto_captured'], $again['invoices_reconciled']]);
        $this->assertSame(2, $alerts()->count(), 'The alert is sent once.');
    }

    public function test_a_charge_left_pending_at_the_gateway_is_not_a_failure_and_is_retried_after_the_payment_window(): void
    {
        $subscription = $this->subscribe($this->plan(10098))->fresh();
        $invoice = Invoice::firstOrFail();
        $this->assertSame(['active', 0, 'open'], [$subscription->status, $subscription->retry_count, $invoice->status]);
        $this->assertEqualsWithDelta(now()->addMinutes(31)->timestamp, $subscription->next_retry_at->timestamp, 5);
        $this->assertSame(0, Notification::where('type', 'subscription_past_due')->count(), 'No past-due alert while the gateway decides.');

        $this->travel(32)->minutes();
        Payment::where('invoice_id', $invoice->id)->update(['status' => 'expired']); // The 30-minute payment window passed.
        $this->assertSame(1, app(BillingService::class)->runScheduled()['billing_retries']);
        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame([0, 'active'], [$subscription->fresh()->retry_count, $subscription->fresh()->status]);
    }

    public function test_a_trial_whose_first_charge_is_left_pending_ends_without_a_dunning_step(): void
    {
        $subscription = $this->subscribe($this->plan(10098, 0, 14)); // Sandbox keeps amounts ending in 98 pending.
        $this->travel(15)->days();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['subscriptions_renewed']);
        $subscription->refresh();
        $this->assertSame(['active', 0, 'open'], [$subscription->status, $subscription->retry_count, Invoice::sole()->status], 'The trial is over while the gateway decides, and it is not past due.');
        $this->assertSame(0, Notification::where('type', 'subscription_past_due')->count());
    }

    public function test_a_retry_rescheduled_after_candidate_selection_is_not_charged_again(): void
    {
        $subscription = $this->subscribe($this->plan(10099));
        $this->travelTo($subscription->next_retry_at);
        $dispatcher = Subscription::getEventDispatcher();
        Subscription::setEventDispatcher(clone $dispatcher);
        $changed = false;
        Subscription::retrieved(function (Subscription $row) use ($subscription, &$changed) {
            if (! $changed && $row->id === $subscription->id && $row->next_retry_at?->lte(now())) {
                $changed = true;
                Subscription::whereKey($row->id)->update(['next_retry_at' => now()->addDay()]);
            }
        });
        try {
            $this->assertSame(0, app(BillingService::class)->runScheduled()['billing_retries']);
        } finally {
            Subscription::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($changed, 'The retry changed after the scheduler selected its candidate.');
        $this->assertSame(1, Payment::where('invoice_id', Invoice::sole()->id)->count());
        $this->assertSame(1, $subscription->fresh()->retry_count);
    }

    public function test_invoice_charge_and_reconciliation_acquire_subscription_before_invoice(): void
    {
        $subscription = $this->subscribe($this->plan(10099));
        $invoice = Invoice::sole();
        DB::enableQueryLog();
        app(BillingService::class)->charge($invoice);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $subscriptionQuery = array_key_first(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "subscriptions"')));
        $invoiceQuery = array_key_first(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "invoices"')));
        $this->assertLessThan($invoiceQuery, $subscriptionQuery, 'Charge uses cancellation\'s subscription-first lock order.');

        $payment = Payment::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $payment->update(['status' => 'captured', 'captured_amount' => $payment->total_amount]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['invoices_reconciled']);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $subscriptionQuery = array_key_first(array_filter($queries, fn ($sql) => str_contains($sql, 'select * from "subscriptions" where "subscriptions"."id"')));
        $invoiceQuery = array_key_first(array_filter($queries, fn ($sql) => str_contains($sql, 'select * from "invoices" where "invoices"."id"')));
        $this->assertLessThan($invoiceQuery, $subscriptionQuery, 'Reconciliation uses the same lock order.');
        $this->assertSame(['active', 'paid'], [$subscription->fresh()->status, $invoice->fresh()->status]);
    }

    public function test_period_end_cancellation_closes_invoices_whose_gateway_attempt_has_expired(): void
    {
        $subscription = $this->subscribe($this->plan(10098));
        $invoice = Invoice::sole();
        app(BillingService::class)->cancel($subscription);
        Payment::where('invoice_id', $invoice->id)->update(['status' => 'expired']);
        $this->travelTo($subscription->current_period_end);
        app(BillingService::class)->runScheduled();
        $this->assertSame(['cancelled', null, 'void'], [$subscription->fresh()->status, $subscription->fresh()->next_retry_at, $invoice->fresh()->status]);
        $this->assertSame(0, app(BillingService::class)->runScheduled()['billing_retries']);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_billing_notifications_follow_active_permissions_instead_of_role_names(): void
    {
        $editor = User::create(['name' => 'Subscription editor', 'email' => 'subscription.editor@example.com', 'password' => 'password', 'is_active' => true]);
        $editor->givePermissionTo('edit subscriptions');
        $reviewer = User::create(['name' => 'Settlement reviewer', 'email' => 'settlement.reviewer@example.com', 'password' => 'password', 'is_active' => true]);
        $reviewer->givePermissionTo('finalize settlements');
        $inactive = User::create(['name' => 'Inactive finance', 'email' => 'billing.inactive@example.com', 'password' => 'password', 'is_active' => false]);
        $inactive->assignRole('Accounting Staff');
        $this->subscribe($this->plan(10099));
        $this->assertSame([$this->admin->id, $this->finance->id, $editor->id], Notification::where('type', 'subscription_past_due')->orderBy('user_id')->pluck('user_id')->all());
        $this->paidOrder([[$this->seller(), 10000]]);
        $this->travel(7)->days();
        app(BillingService::class)->runScheduled();
        $this->assertSame([$this->admin->id, $this->finance->id, $reviewer->id], Notification::where('type', 'settlement_ready')->orderBy('user_id')->pluck('user_id')->all());
    }

    public function test_billing_summaries_and_options_only_expose_permitted_resources(): void
    {
        $seller = $this->seller();
        $this->subscribe($this->plan(10000), 'monthly', $seller);
        $this->paidOrder([[$seller, 10000]]);
        $day = now('Asia/Manila')->toDateString();
        app(BillingService::class)->generate($day, $day, $seller->id);
        Category::create(['source_system' => 'orders', 'external_id' => 'private-billing-category', 'name' => 'Private category']);
        Setting::updateOrCreate(['key' => 'settlement_auto_finalize_days'], ['value' => 2]);
        $viewer = User::create(['name' => 'Plan viewer', 'email' => 'billing.viewer@example.com', 'password' => 'password', 'is_active' => true]);
        $viewer->givePermissionTo('view plans');
        $token = $viewer->createToken('staff', ['staff'])->plainTextToken;
        $asViewer = function () use ($token) { $this->app['auth']->forgetGuards(); return $this->withToken($token); };
        $asViewer()->getJson('/api/v1/plans')->assertOk()->assertJsonPath('summary.active', 0)->assertJsonPath('summary.mrr_amount', 0)->assertJsonPath('summary.commission_amount', 0)->assertJsonPath('summary.payable_amount', 0);
        $asViewer()->getJson('/api/v1/billing/options')->assertForbidden();
        $viewer->givePermissionTo('view subscriptions');
        $asViewer()->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('summary.active', 1)->assertJsonPath('summary.mrr_amount', 10000)->assertJsonPath('summary.commission_amount', 0)->assertJsonPath('summary.payable_amount', 0);
        $asViewer()->getJson('/api/v1/billing/options')->assertOk()->assertJsonMissingPath('sellers.0.payout_destination_masked')->assertJsonCount(0, 'categories')->assertJsonPath('settlement_auto_finalize_days', 0)->assertJsonPath('current_subscription_seller_ids', [$seller->id]);
        $viewer->givePermissionTo('view commissions', 'view settlements', 'view commission rules', 'pay settlements');
        $asViewer()->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('summary.commission_amount', 500)->assertJsonPath('summary.payable_amount', 9500);
        $asViewer()->getJson('/api/v1/billing/options')->assertOk()->assertJsonPath('sellers.0.payout_destination_masked', '•••• 5678')->assertJsonCount(1, 'categories')->assertJsonPath('settlement_auto_finalize_days', 2);
    }

    public function test_scheduled_settlements_skip_sellers_without_sales_and_send_one_digest(): void
    {
        [$selling, $idle] = [$this->seller(), $this->seller()];
        $this->paidOrder([[$selling, 10000]]);
        $this->travel(7)->days(); // The order's week is now the last complete settlement period.
        $first = app(BillingService::class)->runScheduled();
        $this->assertSame([1, 1], [$first['settlements_generated'], $first['settlements_checked']]);
        $this->assertSame(0, Settlement::where('seller_id', $idle->id)->count(), 'A seller without sales gets no empty draft.');
        $digest = Notification::where('user_id', $this->finance->id)->where('type', 'settlement_ready')->sole();
        $this->assertSame(1, $digest->data['count']);
        $this->assertArrayHasKey('period_start', $digest->data);
        $this->assertArrayNotHasKey('settlement_id', $digest->data);

        $this->assertSame(0, app(BillingService::class)->runScheduled()['settlements_generated']);
        $this->assertSame(1, Notification::where('user_id', $this->finance->id)->where('type', 'settlement_ready')->count(), 'One bell per run, and none when nothing is new.');
    }

    public function test_scheduled_generation_does_not_create_an_empty_draft_if_another_generation_claimed_the_sales(): void
    {
        $seller = $this->seller();
        $this->paidOrder([[$seller, 10000]]);
        $this->travel(7)->days();
        $start = now('Asia/Manila')->startOfWeek()->subWeek();
        $from = $start->copy()->subDay()->toDateString();
        $to = $start->copy()->addDays(6)->toDateString();
        $dispatcher = Seller::getEventDispatcher();
        Seller::setEventDispatcher(clone $dispatcher);
        $claimed = false;
        Seller::retrieved(function (Seller $candidate) use ($seller, $from, $to, &$claimed) {
            if (! $claimed && $candidate->id === $seller->id) {
                $claimed = true;
                app(BillingService::class)->generate($from, $to, $seller->id, $this->finance->id);
            }
        });
        try {
            $result = app(BillingService::class)->runScheduled();
            $this->assertSame([0, 0], [$result['settlements_checked'], $result['settlements_generated']]);
        } finally {
            Seller::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($claimed);
        $this->assertSame(1, Settlement::count(), 'Only the manual draft exists; the scheduled draft lost its sales before locking.');
        $this->assertSame(9500, Settlement::sole()->net_payable_amount);
    }

    public function test_auto_finalize_is_off_by_default_then_finalizes_old_scheduler_drafts_once_and_leaves_held_ones(): void
    {
        $client = IntegrationClient::create(['name' => 'Orders storefront', 'system_code' => 'orders', 'is_active' => true]);
        WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/hook', 'events' => ['settlement.finalized'], 'secret' => 'secret', 'is_active' => true]);
        $sellers = array_map(fn () => $this->seller(), range(1, 6));
        [$ready, $held, $negative, $disputed, $refunding, $manual] = $sellers;
        $payments = array_map(fn (Seller $seller) => $this->paidOrder([[$seller, 10000]]), $sellers);
        $payments[0]->update(['fee_amount' => 300]);
        $this->travel(7)->days(); // The orders' week is now the last complete settlement period.
        $week = now('Asia/Manila')->startOfWeek()->subWeek();
        app(BillingService::class)->generate($week->toDateString(), $week->addDays(6)->toDateString(), $manual->id, $this->finance->id); // By hand.
        $first = app(BillingService::class)->runScheduled();
        $this->assertSame([5, 0], [$first['settlements_generated'], $first['settlements_auto_finalized']], 'The rule is off by default.');
        $draft = fn (Seller $seller) => Settlement::where('seller_id', $seller->id)->where('status', '!=', 'void')->sole();
        $this->asFinance()->postJson("/api/v1/settlements/{$draft($held)->id}/void")->assertOk();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['settlements_generated'], 'The voided draft is rebuilt, and held.');
        $draft($negative)->update(['net_payable_amount' => -100]);
        $payments[3]->update(['status' => 'disputed']);
        Refund::create(['payment_id' => $payments[4]->id, 'total_amount' => 1000, 'reason' => 'damaged', 'status' => 'requested']);

        $this->app['auth']->forgetGuards();
        $asAdmin = $this->withToken($this->admin->createToken('staff', ['staff'])->plainTextToken);
        $asAdmin->putJson('/api/v1/settings', ['settlement_auto_finalize_days' => 31])->assertUnprocessable()->assertJsonValidationErrors('settlement_auto_finalize_days');
        $asAdmin->putJson('/api/v1/settings', ['settlement_auto_finalize_days' => 2])->assertOk()->assertJsonPath('settlement_auto_finalize_days', 2);
        $this->asFinance()->getJson('/api/v1/billing/options')->assertOk()->assertJsonPath('settlement_auto_finalize_days', 2);
        $this->asFinance()->getJson("/api/v1/settlements/{$draft($ready)->id}")->assertOk()->assertJsonPath('auto_finalize_at', fn ($at) => is_string($at));
        $this->asFinance()->getJson("/api/v1/settlements/{$draft($held)->id}")->assertOk()->assertJsonPath('auto_finalize_at', null);

        $this->travel(1)->days();
        $this->assertSame(0, app(BillingService::class)->runScheduled()['settlements_auto_finalized'], 'Drafts younger than the setting wait.');
        $this->travel(2)->days();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['settlements_auto_finalized']);
        $this->assertSame('finalized', $draft($ready)->status);
        foreach ([$held, $negative, $disputed, $refunding, $manual] as $seller) {
            $this->assertSame('draft', $draft($seller)->status, "Seller {$seller->id} stays a draft for Finance.");
        }
        $audit = AuditLog::where('entity_type', 'Settlement')->where('entity_id', (string) $draft($ready)->id)->sole();
        $this->assertSame([null, 'settlement_auto_finalize'], [$audit->user_id, $audit->new_values['automation']]);
        $this->assertSame(0, app(BillingService::class)->runScheduled()['settlements_auto_finalized']);
        $this->assertSame([300, 300], LedgerEntry::where('entry_type', 'gateway_fee')->pluck('total_amount')->all(), 'One gateway_fee pair.');
        $this->assertSame(1, WebhookDelivery::where('event_type', 'settlement.finalized')->count());
        $this->assertLedgerBalanced();
    }

    public function test_catch_up_generates_the_last_eight_periods_with_unsettled_entries_and_rebuilds_a_voided_older_draft_once(): void
    {
        [$late, $older, $recent] = [$this->seller(), $this->seller(), $this->seller()];
        $this->paidOrder([[$late, 10000]]);
        $this->travel(7)->weeks();
        $this->paidOrder([[$older, 10000]]);
        $this->travel(2)->weeks();
        $this->paidOrder([[$recent, 10000]]);
        $this->travel(2)->weeks(); // Periods back: late 11 (beyond catch-up), older 4, recent 2.
        $first = app(BillingService::class)->runScheduled();
        $this->assertSame(2, $first['settlements_generated']);
        $this->assertSame(0, Settlement::where('seller_id', $late->id)->count(), 'Only the last eight periods are caught up.');
        $digest = Notification::where('user_id', $this->finance->id)->where('type', 'settlement_ready')->sole();
        $this->assertSame([2, now('Asia/Manila')->startOfWeek()->subWeeks(4)->toDateString()], [$digest->data['count'], $digest->data['period_start']], 'One digest for every period of the run.');

        $old = Settlement::where('seller_id', $older->id)->sole();
        $this->asFinance()->postJson("/api/v1/settlements/{$old->id}/void")->assertOk();
        $this->assertSame(1, app(BillingService::class)->runScheduled()['settlements_generated'], 'A voided older draft is rebuilt.');
        $this->assertSame(0, app(BillingService::class)->runScheduled()['settlements_generated'], 'A second run creates nothing.');
        $this->assertSame(1, Settlement::where('seller_id', $older->id)->where('status', 'draft')->count());
    }

    public function test_payouts_default_to_the_sellers_stored_method_and_need_a_destination(): void
    {
        $wallet = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.Str::random(8), 'shop_name' => 'Wallet shop', 'payout_destination' => ['number' => '09170001234']]);
        $none = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.Str::random(8), 'shop_name' => 'No destination shop']);
        $bank = $this->seller();
        $this->paidOrder([[$bank, 10000], [$wallet, 20000], [$none, 30000]]);
        $day = now('Asia/Manila')->toDateString();
        [$b, $w, $n] = array_map(function (Seller $seller) use ($day) {
            $settlement = app(BillingService::class)->generate($day, $day, $seller->id)[0];
            app(BillingService::class)->settlementAction($settlement, 'finalize');

            return $settlement->id;
        }, [$bank, $wallet, $none]);

        $this->postJson("/api/v1/settlements/{$b}/pay")->assertUnauthorized();
        $agent = User::create(['name' => 'Billing agent', 'email' => 'billing.agent@example.com', 'password' => 'password', 'is_active' => true]);
        $agent->assignRole('Administrative Assistant');
        $this->withToken($agent->createToken('staff', ['staff'])->plainTextToken)->postJson("/api/v1/settlements/{$b}/pay")->assertForbidden();

        $this->asFinance()->postJson("/api/v1/settlements/{$b}/pay")->assertOk()->assertJsonPath('payouts.0.method', 'bank_transfer')->assertJsonPath('payouts.0.destination_masked', '•••• 5678');
        $this->asFinance()->postJson("/api/v1/settlements/{$w}/pay", ['method' => null])->assertOk()->assertJsonPath('payouts.0.method', 'gcash')->assertJsonPath('payouts.0.destination_masked', '•••• 1234');
        $this->asFinance()->postJson("/api/v1/settlements/{$n}/pay")->assertUnprocessable()->assertJsonValidationErrors('destination_masked');
        $this->asFinance()->postJson("/api/v1/settlements/{$n}/pay", ['destination_masked' => '•••• 4321'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->asFinance()->postJson("/api/v1/settlements/{$n}/pay", ['method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->asFinance()->postJson("/api/v1/settlements/{$b}/pay")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(4, LedgerEntry::where('entry_type', 'payout')->count(), 'Two payouts, two legs each: a repeated click adds no ledger rows.');
        $this->assertSame('finalized', Settlement::findOrFail($n)->status);
        $this->assertLedgerBalanced();
    }

    public function test_a_payment_fee_split_between_sellers_sums_exactly_to_the_fee(): void
    {
        [$first, $second] = [$this->seller(), $this->seller()];
        $this->paidOrder([[$first, 5000], [$second, 5000]])->update(['fee_amount' => 3]);
        $day = now('Asia/Manila')->toDateString();
        app(BillingService::class)->generate($day, $day);
        // Rounding each half separately would charge 2 + 2 = 4 centavos for a 3-centavo fee.
        $this->assertSame([2, 1], [Settlement::where('seller_id', $first->id)->value('gateway_fees_amount'), Settlement::where('seller_id', $second->id)->value('gateway_fees_amount')]);
    }

    public function test_commission_rules_apply_seller_then_plan_then_category_then_global_and_round_half_up(): void
    {
        $category = Category::create(['source_system' => 'orders', 'external_id' => 'rule-category', 'name' => 'Rule category']);
        CommissionRule::create(['scope' => 'category', 'scope_id' => $category->id, 'rate_bp' => 750, 'fixed_fee_amount' => 0, 'effective_from' => now()->subDay()]);
        $plain = $this->seller();
        $payment = $this->paidOrder([[$plain, 33333, $category]]);
        // ₱333.33 × 7.5% = ₱24.999 75 → ₱25.00 (half up, once).
        $entry = CommissionEntry::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame([750, 2500, 30833], [$entry->rate_bp, $entry->commission_amount, $entry->seller_net_amount]);

        $plan = $this->plan(1000);
        CommissionRule::create(['scope' => 'plan', 'scope_id' => $plan->id, 'rate_bp' => 600, 'fixed_fee_amount' => 0, 'effective_from' => now()->subDay()]);
        [$planSeller, $ruledSeller] = [$this->seller(), $this->seller()];
        CommissionRule::create(['scope' => 'seller', 'scope_id' => $ruledSeller->id, 'rate_bp' => 300, 'fixed_fee_amount' => 500, 'effective_from' => now()->subDay()]);
        $subscription = fn (Seller $seller) => Subscription::create(['seller_id' => $seller->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()])->load('plan');
        $item = fn (Seller $seller, ?Category $in = null) => new OrderItem(['seller_id' => $seller->id, 'category_id' => $in?->id]);
        $rules = app(CommissionService::class);
        $this->assertSame('seller', $rules->resolveRule($item($ruledSeller, $category), $subscription($ruledSeller))->scope);
        $this->assertSame('plan', $rules->resolveRule($item($planSeller, $category), $subscription($planSeller))->scope);
        $this->assertSame('category', $rules->resolveRule($item($plain, $category))->scope);
        $this->assertSame('global', $rules->resolveRule($item($plain))->scope);
        CommissionRule::create(['scope' => 'category', 'scope_id' => $category->id, 'rate_bp' => 900, 'fixed_fee_amount' => 0, 'effective_from' => now()->subHour()]);
        $this->assertSame(900, $rules->resolveRule($item($plain, $category))->rate_bp, 'Within a scope the latest effective rule wins.');
    }

    public function test_exactly_one_global_rule_is_active_and_global_rules_never_overlap(): void
    {
        $base = ['scope' => 'global', 'scope_id' => null, 'rate_bp' => 600, 'fixed_fee_amount' => 0];
        $this->asFinance()->postJson('/api/v1/commission-rules', $base + ['effective_from' => now()->subYears(2)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->asFinance()->postJson('/api/v1/commission-rules', $base + ['effective_from' => now()->addDay()->toIso8601String(), 'effective_to' => now()->addDays(9)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('effective_to');
        $starts = now()->addDay()->startOfSecond();
        $this->asFinance()->postJson('/api/v1/commission-rules', $base + ['effective_from' => $starts->toIso8601String()])->assertCreated();
        $this->assertTrue($this->global->fresh()->effective_to->equalTo($starts), 'The replaced global rule ends when the new one starts.');
        $this->asFinance()->deleteJson('/api/v1/commission-rules/'.$this->global->id)->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->assertSame(2, CommissionRule::where('scope', 'global')->count());
    }

    public function test_refund_reversals_are_proportional_and_never_exceed_the_original_commission(): void
    {
        $payment = $this->paidOrder([[$this->seller(), 33333]]);
        $this->assertSame(1667, (int) CommissionEntry::where('payment_id', $payment->id)->sum('commission_amount')); // 1,666.65 → 1,667
        $service = app(PaymentService::class);
        foreach ([11111, 22222] as $amount) {
            $service->approve($service->requestRefund($payment->fresh(), ['amount' => $amount, 'reason' => 'damaged'], $this->admin), $this->finance);
        }
        $reversals = CommissionEntry::where('payment_id', $payment->id)->where('entry_type', 'reversal')->pluck('commission_amount')->all();
        $this->assertSame([-556, -1111], $reversals);
        $this->assertSame(0, (int) CommissionEntry::where('payment_id', $payment->id)->sum('commission_amount'));
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_mrr_divides_annual_prices_by_twelve_rounding_half_up(): void
    {
        foreach ([[$this->plan(199900), 'monthly'], [$this->plan(1, 1000002), 'annual']] as [$plan, $cycle]) {
            Subscription::create(['seller_id' => $this->seller()->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        }
        // 199,900 + round_half_up(1,000,002 ÷ 12 = 83,333.5) = 199,900 + 83,334.
        $this->asFinance()->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('summary.mrr_amount', 283234);
    }

    public function test_billing_lists_search_by_the_seller_shop_name(): void
    {
        $plan = $this->plan(100000);
        $found = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.Str::random(8), 'shop_name' => 'Mabuhay Crafts']);
        $this->subscribe($plan, 'monthly', $found);
        $this->subscribe($plan);
        $this->asFinance()->getJson('/api/v1/subscriptions?search=mabuhay')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.seller_id', $found->id);
        $this->asFinance()->getJson('/api/v1/invoices?search=mabuhay')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.seller_id', $found->id);
    }

    public function test_a_plain_rule_date_starts_at_midnight_in_the_business_time_zone(): void
    {
        $date = now('Asia/Manila')->addDays(3)->toDateString();
        $rule = $this->asFinance()->postJson('/api/v1/commission-rules', ['scope' => 'global', 'scope_id' => null, 'rate_bp' => 600, 'fixed_fee_amount' => 0, 'effective_from' => $date])->assertCreated()->json('id');
        $this->assertTrue(\App\Models\CommissionRule::findOrFail($rule)->effective_from->equalTo(\Carbon\CarbonImmutable::parse($date, 'Asia/Manila')), 'Midnight in Manila, not UTC midnight.');
    }

    public function test_a_scheduled_settlement_that_fails_is_counted_without_blocking_other_sellers_and_is_retried(): void
    {
        [$failing, $fine] = [$this->seller(), $this->seller()];
        $this->paidOrder([[$failing, 10000], [$fine, 20000]]);
        $this->travel(7)->days();
        $dispatcher = Settlement::getEventDispatcher();
        Settlement::setEventDispatcher(clone $dispatcher);
        Settlement::creating(function (Settlement $settlement) use ($failing) {
            if ((int) $settlement->seller_id === $failing->id) { throw new \RuntimeException('Simulated settlement failure.'); }
        });
        try {
            $first = app(BillingService::class)->runScheduled();
        } finally {
            Settlement::setEventDispatcher($dispatcher);
        }
        $this->assertSame([1, 1], [$first['settlements_generated'], $first['billing_errors']], 'The failure shows in billing_errors.');
        $this->assertSame(0, Settlement::where('seller_id', $failing->id)->count());
        $second = app(BillingService::class)->runScheduled();
        $this->assertSame([1, 0], [$second['settlements_generated'], $second['billing_errors']], 'The failed seller is generated on the next run.');
    }

    public function test_scheduled_generation_builds_at_most_one_hundred_drafts_per_period_and_run(): void
    {
        $sellers = array_map(fn () => $this->seller(), range(1, 101));
        $this->paidOrder(array_map(fn (Seller $seller) => [$seller, 100], $sellers));
        $this->travel(7)->days();
        $this->assertSame(100, app(BillingService::class)->runScheduled()['settlements_generated']);
        $this->assertSame(1, app(BillingService::class)->runScheduled()['settlements_generated'], 'The remaining seller follows on the next run.');
        $this->assertSame(0, app(BillingService::class)->runScheduled()['settlements_generated']);
        $this->assertSame(101, Settlement::where('status', 'draft')->count());
    }

    public function test_the_contract_documents_the_payout_and_commission_entries_a_paid_settlement_returns(): void
    {
        $seller = $this->seller();
        $this->paidOrder([[$seller, 10000]]);
        $day = now('Asia/Manila')->toDateString();
        $settlement = app(BillingService::class)->generate($day, $day, $seller->id)[0];
        app(BillingService::class)->settlementAction($settlement, 'finalize');
        $paid = $this->asFinance()->postJson("/api/v1/settlements/{$settlement->id}/pay")->assertOk()->json();
        $schemas = json_decode(file_get_contents(base_path('../docs/openapi.yaml')), true, 512, JSON_THROW_ON_ERROR)['components']['schemas'];
        $this->assertContains($paid['payouts'][0]['status'], $schemas['Payout']['properties']['status']['enum']);
        $this->assertContains($paid['entries'][0]['entry_type'], $schemas['CommissionEntry']['properties']['entry_type']['enum']);
        $this->assertArrayHasKey('seller_net_amount', $schemas['CommissionEntry']['properties']);
    }

    public function test_carry_forward_is_off_by_default_then_nets_a_finalized_negative_settlement_into_the_next_one_once(): void
    {
        $this->putJson('/api/v1/settings', ['settlement_carry_forward' => true])->assertUnauthorized();
        $seller = $this->seller();
        $other = $this->seller();
        $owed = fn (Seller $owner) => Settlement::create(['seller_id' => $owner->id, 'period_start' => now()->subWeeks(3)->toDateString(), 'period_end' => now()->subWeeks(3)->addDays(6)->toDateString(), 'net_payable_amount' => -310, 'status' => 'finalized', 'finalized_at' => now()]);
        $negative = $owed($seller);
        $untouched = $owed($other);
        $this->paidOrder([[$seller, 10000]]);
        $period = ['period_start' => now('Asia/Manila')->toDateString(), 'period_end' => now('Asia/Manila')->toDateString(), 'seller_id' => $seller->id];

        $off = $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()
            ->assertJsonPath('data.0.adjustments_amount', 0)->assertJsonPath('data.0.net_payable_amount', 9500)->json('data.0.id');
        $this->assertNull($negative->fresh()->recovered_by_settlement_id, 'The rule is off by default.');
        $this->asFinance()->postJson("/api/v1/settlements/{$off}/void")->assertOk();

        $this->asFinance()->putJson('/api/v1/settings', ['settlement_carry_forward' => true])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $asAdmin = $this->withToken($this->admin->createToken('staff', ['staff'])->plainTextToken);
        $asAdmin->putJson('/api/v1/settings', ['settlement_carry_forward' => 'sometimes'])->assertUnprocessable()->assertJsonValidationErrors('settlement_carry_forward');
        $asAdmin->putJson('/api/v1/settings', ['settlement_carry_forward' => true])->assertOk()->assertJsonPath('settlement_carry_forward', true);

        $first = $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()
            ->assertJsonPath('data.0.adjustments_amount', -310)->assertJsonPath('data.0.net_payable_amount', 9190)->json('data.0.id');
        $this->assertSame($first, $negative->fresh()->recovered_by_settlement_id);
        $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.adjustments_amount', -310);
        $this->asFinance()->getJson('/api/v1/settlements')->assertOk()->assertJsonPath('summary.payable_amount', 9190 - 310, 'The other seller still owes; the recovered debt is not counted twice.');
        $empty = app(BillingService::class)->generate($period['period_start'], $period['period_end'], $other->id)[0];
        $this->assertSame([0, 0], [$empty->adjustments_amount, $empty->net_payable_amount], 'A settlement without sales carries nothing.');
        $this->assertNull($untouched->fresh()->recovered_by_settlement_id);

        $this->asFinance()->postJson("/api/v1/settlements/{$first}/void")->assertOk();
        $this->assertNull($negative->fresh()->recovered_by_settlement_id, 'Voiding releases the carried debt.');
        $second = $this->asFinance()->postJson('/api/v1/settlements/generate', $period)->assertCreated()->assertJsonPath('data.0.net_payable_amount', 9190)->json('data.0.id');
        $this->assertSame($second, $negative->fresh()->recovered_by_settlement_id);
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/finalize")->assertOk();
        $this->asFinance()->postJson("/api/v1/settlements/{$second}/pay")->assertOk()->assertJsonPath('payouts.0.amount', 9190);
        $this->assertSame(0, LedgerEntry::where('entry_type', 'adjustment')->count(), 'The debt is already in seller payable; no new ledger pair.');
        $this->assertLedgerBalanced();
    }

    public function test_free_plan_auto_subscribe_is_off_by_default_validated_and_subscribes_each_new_seller_once_for_its_owner(): void
    {
        $free = $this->plan(0);
        $paid = $this->plan(10000);
        $rated = $this->plan(0); $rated->update(['commission_rate_bp' => 300]);
        $inactive = $this->plan(0); $inactive->update(['is_active' => false]);
        $new = $this->seller();
        $cancelled = $this->subscribe($this->plan(5000), 'monthly', $this->seller());
        app(BillingService::class)->cancel($cancelled, true);
        $endpoints = [];
        foreach (['orders', 'other'] as $system) {
            $client = IntegrationClient::create(['name' => $system.' storefront', 'system_code' => $system, 'is_active' => true]);
            $endpoints[$system] = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/'.$system, 'events' => ['subscription.created'], 'secret' => 'secret', 'is_active' => true]);
        }
        $this->assertSame(0, app(BillingService::class)->runScheduled()['subscriptions_auto_created'], 'The rule is off by default.');
        $this->assertSame(0, Subscription::where('seller_id', $new->id)->count());

        $this->putJson('/api/v1/settings', ['default_plan_id' => $free->id])->assertUnauthorized();
        $this->asFinance()->putJson('/api/v1/settings', ['default_plan_id' => $free->id])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $asAdmin = $this->withToken($this->admin->createToken('staff', ['staff'])->plainTextToken);
        foreach ([$paid->id, $rated->id, $inactive->id, 999999, 'free'] as $invalid) {
            $asAdmin->putJson('/api/v1/settings', ['default_plan_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('default_plan_id');
        }
        $asAdmin->putJson('/api/v1/settings', ['default_plan_id' => $free->id])->assertOk()->assertJsonPath('default_plan_id', $free->id);

        $this->assertSame(1, app(BillingService::class)->runScheduled()['subscriptions_auto_created']);
        $subscription = Subscription::where('seller_id', $new->id)->sole();
        $this->assertSame([$free->id, 'monthly', 'active'], [$subscription->plan_id, $subscription->billing_cycle, $subscription->status]);
        $this->assertSame(['paid', 0, null], [$subscription->invoices()->sole()->status, $subscription->invoices()->sole()->total_amount, $subscription->invoices()->sole()->payment_id]);
        $this->assertSame(1, Subscription::where('seller_id', $cancelled->seller_id)->count(), 'A seller that had a subscription is never subscribed again.');
        $audit = AuditLog::where('entity_type', 'Subscription')->where('entity_id', (string) $subscription->id)->sole();
        $this->assertSame([null, 'subscription_auto_subscribe'], [$audit->user_id, $audit->new_values['automation']]);
        $this->assertSame([$endpoints['orders']->id], WebhookDelivery::where('event_type', 'subscription.created')->pluck('endpoint_id')->all(), 'Only the seller\'s own client is told.');
        $this->assertSame(0, app(BillingService::class)->runScheduled()['subscriptions_auto_created'], 'Idempotent.');

        $later = $this->seller();
        $free->update(['monthly_price_amount' => 100]);
        $this->assertSame(0, app(BillingService::class)->runScheduled()['subscriptions_auto_created'], 'A plan that is no longer free is skipped.');
        $this->assertSame(0, Subscription::where('seller_id', $later->id)->count());
        $asAdmin->putJson('/api/v1/settings', ['default_plan_id' => null])->assertOk()->assertJsonPath('default_plan_id', null);
    }
}
