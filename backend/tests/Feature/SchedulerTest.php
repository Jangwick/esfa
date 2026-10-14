<?php

namespace Tests\Feature;

use App\Gateways\GatewayEvent;
use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SchedulerLock;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Services\Scheduler;
use App\Services\WebhookService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml pauses scheduled work so requests never run it in the background; these tests run it directly.
        config(['sfa.scheduler_enabled' => true]);
    }

    private function pendingPayment(): Payment
    {
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-'.Str::random(6), 'name' => 'Scheduler customer']);
        // Sandbox amounts ending in 98 stay pending until a webhook arrives, so they expire after 30 minutes.
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-'.Str::random(6), 'customer_id' => $customer->id, 'subtotal_amount' => 10098, 'total_amount' => 10098, 'placed_at' => now()]);

        return app(PaymentService::class)->create($order, 'card')['payment'];
    }

    public function test_repeated_runs_are_idempotent_and_report_counts(): void
    {
        $payment = $this->pendingPayment();
        $this->travel(31)->minutes();
        $first = app(Scheduler::class)->run();
        $this->assertTrue($first['ran']);
        $this->assertSame(1, $first['payments_expired']);
        $this->assertSame([], $first['failed_jobs']);
        $this->assertSame('expired', $payment->fresh()->status);
        foreach (['tickets_auto_assigned', 'ticket_sla_notifications', 'tickets_auto_closed', 'tickets_auto_resolved', 'subscriptions_renewed', 'billing_retries', 'settlements_checked', 'settlements_generated', 'settlements_auto_finalized', 'webhook_attempts', 'idempotency_pruned', 'webhook_endpoints_recovered', 'token_expiry_reminders', 'notifications_cleared', 'notifications_pruned', 'payments_auto_captured', 'payments_auto_capture_errors', 'finance_reminders_sent', 'risk_auto_cleared', 'invoices_reconciled', 'shipments_overdue_flagged', 'shipments_stuck_flagged', 'subscriptions_auto_created'] as $count) {
            $this->assertArrayHasKey($count, $first);
        }
        $second = app(Scheduler::class)->run();
        $this->assertTrue($second['ran']);
        $this->assertSame(0, $second['payments_expired']);
        $this->assertSame(0, $second['subscriptions_renewed']);
        $this->assertSame(0, $second['billing_retries']);
    }

    public function test_a_held_lock_blocks_concurrent_runs_and_a_stale_lock_is_recovered(): void
    {
        $payment = $this->pendingPayment();
        $this->travel(31)->minutes();
        SchedulerLock::create(['name' => 'scheduled-work', 'owner' => (string) Str::uuid(), 'locked_until' => now()->addMinutes(4), 'last_run_at' => now()->subMinute()]);
        $this->assertFalse(app(Scheduler::class)->run()['ran']);
        $this->assertSame('pending', $payment->fresh()->status, 'Nothing runs while another process holds the lock.');

        $this->travel(5)->minutes();
        $result = app(Scheduler::class)->run();
        $this->assertTrue($result['ran'], 'A lock whose holder died is recovered after it expires.');
        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertNull(SchedulerLock::find('scheduled-work')->locked_until, 'The lock is released after the run.');
    }

    public function test_one_failing_job_does_not_stop_the_others(): void
    {
        $payment = $this->pendingPayment();
        $this->travel(31)->minutes();
        $this->mock(BillingService::class, fn ($mock) => $mock->shouldReceive('runScheduled')->once()->andThrow(new RuntimeException('Billing provider unavailable.')));
        $result = app(Scheduler::class)->run();
        $this->assertTrue($result['ran']);
        $this->assertSame(['billing'], $result['failed_jobs']);
        $this->assertSame(1, $result['payments_expired']);
        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertNull(SchedulerLock::find('scheduled-work')->locked_until);
    }

    public function test_one_broken_webhook_delivery_does_not_stop_the_other_due_deliveries(): void
    {
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        $endpoint = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/webhooks', 'events' => ['payment.captured'], 'secret' => 'secret', 'is_active' => true]);
        $deliveries = [];
        foreach ([1, 2] as $sequence) {
            $deliveries[] = WebhookDelivery::create(['id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id, 'event_id' => 'evt_'.$sequence,
                'event_type' => 'payment.captured', 'request_body' => '{}', 'status' => 'pending', 'next_attempt_at' => now()->subMinutes(3 - $sequence), 'attempt_log' => []]);
        }
        $this->mock(WebhookService::class, function ($mock) use ($deliveries) {
            $mock->shouldReceive('recoverDisabled')->once()->andReturn(0);
            $mock->shouldReceive('deliver')->once()->withArgs(fn ($delivery) => $delivery->id === $deliveries[0]->id)
                ->andThrow(new RuntimeException('First delivery cannot be recorded.'));
            $mock->shouldReceive('deliver')->once()->withArgs(fn ($delivery) => $delivery->id === $deliveries[1]->id)
                ->andReturnUsing(function ($delivery) { $delivery->update(['status' => 'delivered', 'attempts' => 1, 'next_attempt_at' => null]); return true; });
        });

        $result = app(Scheduler::class)->run();
        $this->assertSame(['webhooks'], $result['failed_jobs']);
        $this->assertSame(1, $result['webhook_attempts']);
        $this->assertSame('pending', $deliveries[0]->fresh()->status);
        $this->assertSame('delivered', $deliveries[1]->fresh()->status);
        $this->assertNull(SchedulerLock::find('scheduled-work')->locked_until);
    }

    public function test_a_failed_job_alerts_active_admins_once_a_day_and_the_run_is_recorded(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = [];
        foreach (['admin' => ['Admin', true], 'inactive' => ['Admin', false], 'finance' => ['Finance', true]] as $key => [$role, $active]) {
            $staff[$key] = User::create(['name' => $key, 'email' => $key.'.failed-job@example.com', 'password' => 'password', 'is_active' => $active]);
            $staff[$key]->assignRole($role);
        }
        $this->mock(BillingService::class, fn ($mock) => $mock->shouldReceive('runScheduled')->andThrow(new RuntimeException('Billing provider unavailable.')));
        $alerts = fn (User $user) => Notification::where('user_id', $user->id)->where('type', 'scheduler_job_failed')->count();

        app(Scheduler::class)->run();
        $this->travel(6)->minutes();
        app(Scheduler::class)->run();
        $this->assertSame(1, $alerts($staff['admin']), 'One alert per job per day.');
        $this->assertSame(['job' => 'billing'], Notification::where('type', 'scheduler_job_failed')->first()->data);
        $this->assertSame(0, $alerts($staff['inactive']));
        $this->assertSame(0, $alerts($staff['finance']));
        $last = SchedulerLock::find('scheduled-work')->last_result;
        $this->assertSame(['billing'], $last['failed_jobs']);
        $this->assertArrayHasKey('finished_at', $last);

        $this->travel(1)->days();
        app(Scheduler::class)->run();
        $this->assertSame(2, $alerts($staff['admin']), 'The next day alerts again.');
    }

    public function test_authenticated_requests_run_due_work_after_the_response_at_most_every_five_minutes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::create(['name' => 'Scheduler admin', 'email' => 'scheduler.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $user->assignRole('Admin');
        $token = $user->createToken('staff', ['staff'])->plainTextToken;
        $this->freezeTime();
        config(['sfa.scheduler_enabled' => false]);
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        $this->assertNull(SchedulerLock::find('scheduled-work'), 'SFA_SCHEDULER_ENABLED=false turns post-response work off.');

        config(['sfa.scheduler_enabled' => true]);
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        $first = SchedulerLock::findOrFail('scheduled-work')->last_run_at;
        $this->travel(4)->minutes();
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        $this->assertEquals($first, SchedulerLock::find('scheduled-work')->last_run_at, 'A second request within five minutes does not run the scheduler again.');
        $this->travel(2)->minutes();
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();
        $this->assertTrue(SchedulerLock::find('scheduled-work')->last_run_at->gt($first));

        $this->app['auth']->forgetGuards();
        $before = SchedulerLock::find('scheduled-work')->last_run_at;
        $this->travel(10)->minutes();
        $this->getJson('/api/v1/health')->assertOk();
        $this->assertEquals($before, SchedulerLock::find('scheduled-work')->last_run_at, 'Unauthenticated requests never run scheduled work.');
    }

    public function test_the_pause_switch_stops_artisan_the_simulator_and_direct_runs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::create(['name' => 'Paused admin', 'email' => 'paused.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $admin->assignRole('Admin');
        $payment = $this->pendingPayment();
        $plan = Plan::create(['name' => 'Paused plan', 'code' => 'paused-plan', 'monthly_price_amount' => 100, 'annual_price_amount' => 0, 'trial_days' => 0, 'features' => [], 'limits' => [], 'is_active' => true]);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'paused-seller', 'shop_name' => 'Paused shop']);
        $subscription = Subscription::create(['seller_id' => $seller->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()]);
        $this->travel(31)->minutes();
        config(['sfa.scheduler_enabled' => false, 'sfa.simulator' => true]);

        $this->assertSame(['ran' => false, 'paused' => true, 'webhook_attempts' => 0, 'idempotency_pruned' => 0], app(Scheduler::class)->run());
        $this->artisan('sfa:run-scheduled')->expectsOutputToContain('paused')->assertSuccessful();
        $this->withToken($admin->createToken('staff', ['staff'])->plainTextToken)->postJson('/api/v1/simulator/run-scheduled')
            ->assertOk()->assertJsonPath('ran', false)->assertJsonPath('paused', true);

        $this->assertSame('pending', $payment->fresh()->status, 'A due payment is left unexpired while paused.');
        $this->assertTrue($subscription->fresh()->current_period_end->isPast(), 'A due subscription is left unrenewed while paused.');
        $this->assertNull(SchedulerLock::find('scheduled-work'));
    }

    public function test_settings_fall_back_to_the_config_default(): void
    {
        $this->assertFalse(Setting::valueFor('auto_assign'));
        $this->assertSame(3, Setting::valueFor('risk_failed_attempts'));
        $this->assertSame('explicit', Setting::valueFor('missing_key', 'explicit'));
        Setting::create(['key' => 'auto_assign', 'value' => true]);
        $this->assertTrue(Setting::valueFor('auto_assign'));
    }

    // ADM-04: an endpoint the system disabled after 20 failures, with one failed delivery, an hour later.
    private function autoDisabledEndpoint(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admins = [];
        foreach (['active' => true, 'inactive' => false] as $key => $active) {
            $admins[$key] = User::create(['name' => $key, 'email' => $key.'.recovery@example.com', 'password' => 'password', 'is_active' => $active]);
            $admins[$key]->assignRole('Admin');
        }
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        $endpoint = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/webhooks', 'events' => ['payment.captured'],
            'secret' => 'recovery-secret', 'is_active' => false, 'consecutive_failures' => 20, 'disabled_at' => now()]);
        $delivery = WebhookDelivery::create(['id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id, 'event_id' => 'evt_'.Str::uuid(),
            'event_type' => 'payment.captured', 'request_body' => '{}', 'status' => 'failed', 'attempts' => 6, 'retry_count' => 6, 'attempt_log' => []]);
        Http::preventStrayRequests();
        $this->travel(61)->minutes();

        return [$endpoint, $delivery, $admins];
    }

    public function test_an_auto_disabled_endpoint_that_answers_is_re_enabled_and_its_failures_resent(): void
    {
        [$endpoint, $delivery, $admins] = $this->autoDisabledEndpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);

        $result = app(Scheduler::class)->run();
        $this->assertSame(1, $result['webhook_endpoints_recovered']);
        $this->assertNotContains('webhook_recovery', $result['failed_jobs']);
        $endpoint->refresh();
        $this->assertSame([true, 0, null], [$endpoint->is_active, $endpoint->consecutive_failures, $endpoint->disabled_at]);
        $this->assertSame('delivered', $delivery->fresh()->status, 'The requeued delivery goes out in the same run.');
        Http::assertSent(function ($request) {
            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('X-SFA-Signature')[0], $matches);

            return $request->header('X-SFA-Event')[0] === 'webhook.test' && count($matches) === 3
                && hash_equals(hash_hmac('sha256', $matches[1].'.'.$request->body(), 'recovery-secret'), $matches[2]);
        });
        $recovered = fn (User $user) => Notification::where('type', 'webhook_endpoint_recovered')->where('user_id', $user->id)->count();
        $this->assertSame(1, $recovered($admins['active']));
        $this->assertSame(0, $recovered($admins['inactive']));
        $audit = AuditLog::where('entity_type', 'WebhookEndpoint')->whereNull('user_id')->sole();
        $this->assertSame(['webhook_auto_recover', 1], [$audit->new_values['automation'], $audit->new_values['requeued_deliveries']]);

        $this->assertSame(0, app(Scheduler::class)->run()['webhook_endpoints_recovered']);
        Http::assertSentCount(2); // The probe and the requeued delivery.
    }

    public function test_an_endpoint_that_still_fails_stays_disabled_and_is_probed_at_most_hourly(): void
    {
        [$endpoint, $delivery] = $this->autoDisabledEndpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('Down', 500)]);

        $this->assertSame(0, app(Scheduler::class)->run()['webhook_endpoints_recovered']);
        $this->travel(30)->minutes();
        app(Scheduler::class)->run();
        Http::assertSentCount(1);
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertNotNull($endpoint->fresh()->disabled_at);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(0, Notification::where('type', 'webhook_endpoint_recovered')->count());

        $this->travel(31)->minutes();
        app(Scheduler::class)->run();
        Http::assertSentCount(2);
    }

    public function test_recovery_reserves_the_hourly_probe_before_sending_it(): void
    {
        [$endpoint] = $this->autoDisabledEndpoint();
        Http::fake(function () {
            $this->assertSame(0, app(WebhookService::class)->recoverDisabled(), 'A concurrent recovery cannot probe the same endpoint while the first probe is in flight.');

            return Http::response('', 200);
        });

        $this->assertSame(1, app(WebhookService::class)->recoverDisabled());
        $this->assertTrue($endpoint->fresh()->is_active);
        Http::assertSentCount(1);
    }

    public function test_a_recovery_probe_cannot_re_enable_an_endpoint_whose_client_was_deactivated(): void
    {
        [$endpoint, $delivery] = $this->autoDisabledEndpoint();
        Http::fake(function () use ($endpoint) {
            $endpoint->client->update(['is_active' => false]);

            return Http::response('', 200);
        });

        $result = app(Scheduler::class)->run();
        $this->assertSame(0, $result['webhook_endpoints_recovered']);
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(0, Notification::where('type', 'webhook_endpoint_recovered')->count());
        Http::assertSentCount(1);
    }

    public function test_a_response_from_an_old_webhook_url_cannot_enable_a_changed_endpoint(): void
    {
        [$endpoint, $delivery] = $this->autoDisabledEndpoint();
        Http::fake(function () use ($endpoint) {
            $endpoint->update(['url' => 'https://1.1.1.1/changed-webhooks']);

            return Http::response('', 200);
        });

        $this->assertSame(0, app(Scheduler::class)->run()['webhook_endpoints_recovered']);
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(0, AuditLog::where('entity_type', 'WebhookEndpoint')->whereNull('user_id')->count());
        Http::assertSentCount(1);
    }

    public function test_endpoints_disabled_by_hand_or_with_the_setting_off_are_never_probed(): void
    {
        [$endpoint, , $admins] = $this->autoDisabledEndpoint();
        Http::fake();
        Setting::create(['key' => 'webhook_auto_recover', 'value' => false]);
        $this->assertSame(0, app(Scheduler::class)->run()['webhook_endpoints_recovered']);
        Http::assertNothingSent();

        Setting::where('key', 'webhook_auto_recover')->delete();
        $this->withToken($admins['active']->createToken('staff', ['staff'])->plainTextToken)->putJson("/api/v1/webhook-endpoints/{$endpoint->id}", [
            'client_id' => $endpoint->client_id, 'url' => $endpoint->url, 'events' => $endpoint->events, 'is_active' => false,
        ])->assertOk()->assertJsonPath('disabled_at', null);
        $this->travel(61)->minutes();
        $this->assertSame(0, app(Scheduler::class)->run()['webhook_endpoints_recovered']);
        Http::assertNothingSent();
        $this->assertFalse($endpoint->fresh()->is_active);
    }

    // An order with one seller line, so a capture also books its commission.
    private function payment(int $amount, string $method = 'card'): Payment
    {
        $unique = Str::random(6);
        CommissionRule::firstOrCreate(['scope' => 'global'], ['rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subYear()]);
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-'.$unique, 'name' => 'Scheduler customer']);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.$unique, 'shop_name' => 'Scheduler shop']);
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-'.$unique, 'customer_id' => $customer->id, 'subtotal_amount' => $amount, 'total_amount' => $amount, 'placed_at' => now()]);
        $order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Scheduler item', 'quantity' => 1, 'unit_price_amount' => $amount, 'line_total_amount' => $amount]);

        return app(PaymentService::class)->create($order, $method)['payment'];
    }

    // A sandbox "98" payment stays pending until the gateway's webhook authorizes it.
    private function authorizedLater(): Payment
    {
        $payment = $this->payment(10098);
        app(PaymentService::class)->processEvent('sandbox', new GatewayEvent('evt_'.Str::random(8), 'payment.authorized', $payment->reference, null), []);

        return $payment->fresh();
    }

    public function test_payments_authorized_later_are_captured_once_unless_held_cod_cancelled_or_switched_off(): void
    {
        $late = $this->authorizedLater();
        $review = $this->authorizedLater();
        $review->update(['risk_level' => 'review']);
        $cancelled = $this->authorizedLater();
        $cancelled->order->update(['status_snapshot' => 'cancelled']);
        $cod = $this->authorizedLater();
        $cod->update(['method' => 'cod', 'gateway' => 'cod']);
        Setting::create(['key' => 'auto_capture_authorized', 'value' => false]);
        $this->assertSame(0, app(Scheduler::class)->run()['payments_auto_captured'], 'The setting switches the sweep off.');
        $this->assertSame('authorized', $late->fresh()->status);

        Setting::where('key', 'auto_capture_authorized')->delete();
        $result = app(Scheduler::class)->run();
        $this->assertSame([1, 0], [$result['payments_auto_captured'], $result['payments_auto_capture_errors']]);
        $this->assertSame(['captured', 10098], [$late->fresh()->status, $late->fresh()->captured_amount]);
        $this->assertSame(1, CommissionEntry::where('payment_id', $late->id)->where('entry_type', 'capture')->count());
        $this->assertTrue(LedgerEntry::where('payment_id', $late->id)->exists());
        $this->assertSame((int) LedgerEntry::where('direction', 'debit')->sum('total_amount'), (int) LedgerEntry::where('direction', 'credit')->sum('total_amount'));
        $audit = AuditLog::where('entity_type', 'Payment')->whereNull('user_id')->sole();
        $this->assertSame([(string) $late->id, 'auto_capture_authorized', 'captured'], [$audit->entity_id, $audit->new_values['automation'], $audit->new_values['status']]);
        foreach ([$review, $cancelled, $cod] as $held) {
            $this->assertSame('authorized', $held->fresh()->status, 'Risk reviews, cancelled orders and COD are never captured by the sweep.');
        }
        $this->assertSame(0, app(Scheduler::class)->run()['payments_auto_captured'], 'A second run captures nothing.');
    }

    // PAY-6: a customer with $captured earlier captured payments and $failed declined attempts in the last hour.
    private function customerWith(int $captured, int $failed = 0): Customer
    {
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-'.Str::random(6), 'name' => 'Repeat customer']);
        foreach ([...array_fill(0, $captured, 10000), ...array_fill(0, $failed, 10099)] as $amount) {
            $this->paymentFor($customer, $amount);
        }

        return $customer;
    }

    private function paymentFor(Customer $customer, int $amount): Payment
    {
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-'.Str::random(6), 'customer_id' => $customer->id, 'subtotal_amount' => $amount, 'total_amount' => $amount, 'placed_at' => now()]);

        return app(PaymentService::class)->create($order, 'card')['payment'];
    }

    public function test_risk_reviews_of_trusted_customers_under_the_cap_are_cleared_once_and_others_stay_in_review(): void
    {
        $this->seed(RolePermissionSeeder::class); // A risk review notifies the edit payments holders.
        $trusted = $this->paymentFor($this->customerWith(2), 6000000); // Above the 50,000.00 risk threshold.
        $newcomer = $this->paymentFor($this->customerWith(1), 6000000);
        $overCap = $this->paymentFor($this->customerWith(2), 12000000);
        $failing = $this->paymentFor($this->customerWith(2, 4), 10000); // Flagged for 4 failures in the last hour.
        $disputedCustomer = $this->customerWith(2);
        $disputedCustomer->payments()->first()->update(['status' => 'dispute_lost']);
        $disputed = $this->paymentFor($disputedCustomer, 6000000);
        $cancelled = $this->paymentFor($this->customerWith(2), 6000000);
        $cancelled->order->update(['status_snapshot' => 'cancelled']);
        $held = [$newcomer, $overCap, $failing, $disputed, $cancelled];
        foreach ([$trusted, ...$held] as $payment) {
            $this->assertSame(['authorized', 'review'], [$payment->status, $payment->risk_level]);
        }

        $this->assertSame(0, app(Scheduler::class)->run()['risk_auto_cleared'], 'Off by default (0 captured payments).');
        $this->assertSame('review', $trusted->fresh()->risk_level);

        Setting::create(['key' => 'risk_auto_clear_min_captured', 'value' => 2]);
        $this->assertSame(1, app(Scheduler::class)->run()['risk_auto_cleared']);
        $this->assertSame(['captured', 'none', 6000000], [$trusted->fresh()->status, $trusted->fresh()->risk_level, $trusted->fresh()->captured_amount]);
        $this->assertSame((int) LedgerEntry::where('direction', 'debit')->sum('total_amount'), (int) LedgerEntry::where('direction', 'credit')->sum('total_amount'));
        $audit = AuditLog::where('entity_type', 'Payment')->whereNull('user_id')->sole();
        $this->assertSame([(string) $trusted->id, 'risk_auto_clear', 'review', 'none', 'captured'], [$audit->entity_id, $audit->new_values['automation'], $audit->old_values['risk_level'], $audit->new_values['risk_level'], $audit->new_values['status']]);
        foreach ($held as $payment) {
            $this->assertSame(['authorized', 'review'], [$payment->fresh()->status, $payment->fresh()->risk_level], 'Anyone else stays in review.');
        }
        $this->assertSame(0, app(Scheduler::class)->run()['risk_auto_cleared'], 'A second run clears nothing.');

        Setting::create(['key' => 'risk_auto_clear_max_amount', 'value' => 12000000]);
        $this->assertSame(1, app(Scheduler::class)->run()['risk_auto_cleared'], 'A higher cap lets the larger payment through.');
        $this->assertSame('captured', $overCap->fresh()->status);
    }

    public function test_finance_reminders_go_once_per_overdue_record_to_active_finance_staff_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = [];
        foreach (['admin' => ['Admin', true], 'finance' => ['Finance', true], 'inactive' => ['Finance', false], 'agent' => ['Support Agent', true]] as $key => [$role, $active]) {
            $staff[$key] = User::create(['name' => $key, 'email' => $key.'.reminder@example.com', 'password' => 'password', 'is_active' => $active]);
            $staff[$key]->assignRole($role);
        }
        $refund = app(PaymentService::class)->requestRefund($this->payment(10000), ['amount' => 1000, 'reason' => 'other'], $staff['agent']);
        $held = $this->payment(6000000, 'cod'); // Above the risk threshold: a COD payment held for review.
        $this->payment(10000)->update(['status' => 'disputed']);
        $recipients = fn (string $type) => Notification::where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
        $finance = [$staff['admin']->id, $staff['finance']->id];

        $this->assertSame(2, app(Scheduler::class)->run()['finance_reminders_sent']);
        $this->assertSame($finance, $recipients('payment_disputed'), 'A dispute alerts on the next run.');
        $this->assertSame([], $recipients('refund_approval_overdue'));
        $this->assertSame([], $recipients('risk_review_overdue'));

        $this->travel(241)->minutes();
        $this->assertSame(4, app(Scheduler::class)->run()['finance_reminders_sent']);
        $this->assertSame($finance, $recipients('refund_approval_overdue'));
        $this->assertSame($finance, $recipients('risk_review_overdue'), 'COD risk holds are reminded too.');
        $this->assertSame(['refund_id' => $refund->id, 'reference' => $refund->reference], Notification::where('type', 'refund_approval_overdue')->first()->data);
        $this->assertSame(['payment_id' => $held->id, 'reference' => $held->reference], Notification::where('type', 'risk_review_overdue')->first()->data);

        $this->travel(1)->days();
        $this->assertSame(0, app(Scheduler::class)->run()['finance_reminders_sent'], 'One reminder per record.');
        $this->payment(10000)->update(['status' => 'disputed']);
        Setting::create(['key' => 'finance_reminder_hours', 'value' => 0]);
        $this->assertSame(0, app(Scheduler::class)->run()['finance_reminders_sent'], '0 switches reminders off.');
    }

    // ADM-05
    public function test_expiring_integration_tokens_remind_integration_managers_once_per_token(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = [];
        foreach (['admin' => ['Admin', true], 'inactive' => ['Admin', false], 'finance' => ['Finance', true]] as $key => [$role, $active]) {
            $staff[$key] = User::create(['name' => $key, 'email' => $key.'.tokens@example.com', 'password' => 'password', 'is_active' => $active]);
            $staff[$key]->assignRole($role);
        }
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        $soon = $client->createToken('soon', ['payments:write'], now()->addDays(3))->accessToken;
        $client->createToken('later', ['payments:write'], now()->addDays(10));
        $client->createToken('no expiry', ['payments:write']);
        $client->createToken('expired', ['payments:write'], now()->subDay());
        IntegrationClient::create(['name' => 'Retired', 'system_code' => 'retired', 'is_active' => false])->createToken('soon', ['payments:write'], now()->addDay());
        $reminders = fn (User $user) => Notification::where('type', 'integration_token_expiring')->where('user_id', $user->id)->get();

        $this->assertSame(1, app(Scheduler::class)->run()['token_expiry_reminders']);
        $this->assertSame(['token_id' => (string) $soon->id, 'client_id' => $client->id], $reminders($staff['admin'])->sole()->data);
        $this->assertCount(0, $reminders($staff['inactive']));
        $this->assertCount(0, $reminders($staff['finance']));
        $this->assertSame(0, app(Scheduler::class)->run()['token_expiry_reminders'], 'One reminder per user per token.');
        $this->assertFalse($reminders($staff['admin'])->sole()->is_read);

        $soon->delete();
        app(Scheduler::class)->run();
        $this->assertTrue($reminders($staff['admin'])->sole()->is_read, 'A revoked token clears its reminder.');

        Setting::create(['key' => 'token_expiry_reminder_days', 'value' => 0]);
        $client->createToken('tomorrow', ['payments:write'], now()->addDay());
        $this->assertSame(0, app(Scheduler::class)->run()['token_expiry_reminders'], '0 turns reminders off.');
    }

    public function test_reminded_tokens_do_not_starve_tokens_beyond_the_batch_limit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'token-batch@example.com', 'password' => 'password', 'is_active' => true]);
        $admin->assignRole('Admin');
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        for ($index = 0; $index < 101; $index++) { $client->createToken('token-'.$index, ['payments:read'], now()->addDays(3)); }

        $this->assertSame(100, app(Scheduler::class)->run()['token_expiry_reminders']);
        $this->assertSame(1, app(Scheduler::class)->run()['token_expiry_reminders']);
        $this->assertSame(0, app(Scheduler::class)->run()['token_expiry_reminders']);
        $this->assertSame(101, Notification::where('type', 'integration_token_expiring')->count());
    }

    public function test_read_token_reminders_keep_their_dedupe_until_revocation_despite_short_retention(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'token-retention@example.com', 'password' => 'password', 'is_active' => true]);
        $admin->assignRole('Admin');
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        $token = $client->createToken('soon', ['payments:read'], now()->addDays(7))->accessToken;
        Setting::create(['key' => 'notification_retention_days', 'value' => 1]);
        $this->assertSame(1, app(Scheduler::class)->run()['token_expiry_reminders']);
        $reminder = Notification::where('type', 'integration_token_expiring')->sole();
        $reminder->update(['is_read' => true, 'read_at' => now()]);
        $this->travel(2)->days();

        foreach ([1, 2] as $run) {
            $result = app(Scheduler::class)->run();
            $this->assertSame(0, $result['token_expiry_reminders']);
            $this->assertSame(0, $result['notifications_pruned']);
            $this->assertModelExists($reminder);
        }

        $token->delete();
        $result = app(Scheduler::class)->run();
        $this->assertSame(1, $result['notifications_pruned']);
        $this->assertModelMissing($reminder);
    }

    public function test_one_token_reminder_failure_rolls_back_its_copies_and_does_not_stop_other_tokens(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admins = [];
        foreach (['one', 'two'] as $key) {
            $admin = User::create(['name' => $key, 'email' => $key.'.token-isolation@example.com', 'password' => 'password', 'is_active' => true]);
            $admin->assignRole('Admin');
            $admins[] = $admin;
        }
        $client = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true]);
        $broken = $client->createToken('broken', ['payments:read'], now()->addDays(3))->accessToken;
        $good = $client->createToken('good', ['payments:read'], now()->addDays(4))->accessToken;
        $event = 'eloquent.creating: '.Notification::class;
        Event::listen($event, function ($notification) use ($broken, $admins) {
            if ($notification->type === 'integration_token_expiring' && $notification->data['token_id'] === (string) $broken->id
                && $notification->user_id === $admins[1]->id) {
                throw new RuntimeException('One notification cannot be saved.');
            }
        });
        try {
            $result = app(Scheduler::class)->run();
        } finally {
            Event::forget($event);
        }

        $this->assertSame(['token_reminders'], $result['failed_jobs']);
        $this->assertSame(2, $result['token_expiry_reminders']);
        $this->assertSame(0, Notification::where('type', 'integration_token_expiring')->where('data->token_id', (string) $broken->id)->count());
        $this->assertSame(2, Notification::where('type', 'integration_token_expiring')->where('data->token_id', (string) $good->id)->count());
        $this->assertSame(2, app(Scheduler::class)->run()['token_expiry_reminders'], 'The failed token is retried without duplicating the successful token.');
        $this->assertSame(0, app(Scheduler::class)->run()['token_expiry_reminders']);
    }

    // ADM-09
    public function test_alerts_about_settled_items_are_cleared_for_everyone_and_only_old_read_notifications_are_pruned(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = [];
        foreach (['requester' => 'Admin', 'one' => 'Finance', 'two' => 'Finance'] as $key => $role) {
            $staff[$key] = User::create(['name' => $key, 'email' => $key.'.tidy@example.com', 'password' => 'password', 'is_active' => true]);
            $staff[$key]->assignRole($role);
        }
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-tidy', 'name' => 'Tidy customer']);
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-tidy', 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        $service = app(PaymentService::class);
        $payment = $service->create($order, 'card')['payment'];
        $approved = $service->requestRefund($payment, ['amount' => 100, 'reason' => 'damaged'], $staff['requester']);
        $waiting = $service->requestRefund($payment->fresh(), ['amount' => 100, 'reason' => 'damaged'], $staff['requester']);
        $copies = fn ($refund) => Notification::where('type', 'refund_awaiting_approval')->get()->filter(fn ($row) => $row->data['refund_id'] === $refund->id);
        $this->assertGreaterThan(1, $copies($approved)->count(), 'Every approver got a copy.');

        $service->approve($approved, $staff['one']);
        $this->assertGreaterThanOrEqual($copies($approved)->count(), app(Scheduler::class)->run()['notifications_cleared']);
        $this->assertTrue($copies($approved)->every(fn ($row) => $row->is_read && $row->read_at), 'One approval clears every copy.');
        $this->assertTrue($copies($waiting)->every(fn ($row) => ! $row->is_read), 'A refund still awaiting approval keeps its alerts.');

        $note = fn (bool $read) => Notification::create(['user_id' => $staff['one']->id, 'type' => 'system', 'title' => 'Note', 'message' => 'Note', 'is_read' => $read, 'read_at' => $read ? now() : null]);
        [$oldRead, $oldUnread, $recentRead] = [$note(true), $note(false), $note(true)];
        Notification::whereKey([$oldRead->id, $oldUnread->id])->update(['created_at' => now()->subDays(91)]);
        Setting::create(['key' => 'notification_retention_days', 'value' => 0]);
        $this->assertSame(0, app(Scheduler::class)->run()['notifications_pruned'], '0 keeps every notification.');

        Setting::where('key', 'notification_retention_days')->delete();
        $this->assertSame(1, app(Scheduler::class)->run()['notifications_pruned']);
        $this->assertModelMissing($oldRead);
        $this->assertModelExists($oldUnread);
        $this->assertModelExists($recentRead);
    }

    public function test_the_artisan_command_runs_all_jobs_and_prints_counts(): void
    {
        $this->artisan('sfa:run-scheduled')->expectsOutputToContain('payments_expired')->expectsOutputToContain('failed_jobs')->assertSuccessful();
    }
}
