<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\LoginLockout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Reconciliation;
use App\Models\Refund;
use App\Models\SchedulerLock;
use App\Models\Seller;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PaymentService;
use App\Support\ListResponse;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const REPORTS = ['revenue_by_day', 'payments_by_method', 'refunds_by_reason', 'tickets_by_category', 'ticket_status', 'shipment_status', 'on_time_by_courier', 'top_sellers', 'mrr_by_plan'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        CommissionRule::create(['scope' => 'global', 'rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subYear()]);
    }

    private function as(string $email): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(User::where('email', $email)->firstOrFail()->createToken('analytics', ['staff'])->plainTextToken);
    }

    private function paid(int $amount, string $source = 'orders', ?Seller $seller = null, string $method = 'card', string $customer = 'Analytics customer'): Payment
    {
        $seller ??= Seller::create(['source_system' => $source, 'external_id' => 'seller-'.Str::random(6), 'shop_name' => 'Analytics shop']);
        $buyer = Customer::create(['source_system' => $source, 'external_id' => 'customer-'.Str::random(6), 'name' => $customer]);
        $order = Order::create(['source_system' => $source, 'external_id' => 'order-'.Str::random(6), 'customer_id' => $buyer->id, 'subtotal_amount' => $amount, 'total_amount' => $amount, 'placed_at' => now()]);
        $order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Analytics item', 'quantity' => 1, 'unit_price_amount' => $amount, 'line_total_amount' => $amount]);

        return app(PaymentService::class)->create($order, $method)['payment'];
    }

    public function test_all_nine_reports_render_and_export_as_csv(): void
    {
        $this->paid(10000);
        $reports = $this->as('analyst@example.com')->getJson('/api/v1/analytics/charts')->assertOk()->json('reports');
        $this->assertSame(self::REPORTS, array_keys($reports));
        foreach ($reports as $key => $report) {
            $this->assertSame(['title', 'unit', 'type', 'data'], array_keys($report), $key);
            $csv = $this->as('analyst@example.com')->get('/api/v1/analytics/export?report='.$key)->assertOk();
            $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
            $this->assertStringContainsString('Name,', $csv->streamedContent());
        }
        $this->assertSame(1, collect($reports['payments_by_method']['data'])->firstWhere('name', 'card')['value']);
        $this->as('analyst@example.com')->getJson('/api/v1/analytics/export?report=bogus')->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->as('support.one@example.com')->getJson('/api/v1/analytics/charts')->assertForbidden();
    }

    public function test_date_filters_use_business_time_zone_days_and_seller_and_method_filters_apply(): void
    {
        // 07:00 on 6 October in Manila is still 5 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 07:00:00', 'Asia/Manila'));
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'filtered-seller', 'shop_name' => 'Filtered shop']);
        $this->paid(12300, 'orders', $seller, 'gcash');
        $this->paid(45600);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
        $summary = fn (array $query) => $this->as('analyst@example.com')->getJson('/api/v1/analytics/overview?'.http_build_query($query))->assertOk()->json('summary');
        $this->assertSame(57900, $summary(['date_from' => '2026-10-06', 'date_to' => '2026-10-06'])['captured_amount']);
        $this->assertSame(0, $summary(['date_from' => '2026-10-05', 'date_to' => '2026-10-05'])['captured_amount']);
        $this->assertSame(12300, $summary(['date_from' => '2026-10-06', 'date_to' => '2026-10-06', 'seller_id' => $seller->id])['gmv_amount']);
        $this->assertSame(12300, $summary(['date_from' => '2026-10-06', 'date_to' => '2026-10-06', 'method' => 'gcash'])['captured_amount']);
        $this->as('analyst@example.com')->getJson('/api/v1/analytics/overview?date_from=2026-10-07&date_to=2026-10-06')->assertUnprocessable();
        $this->assertSame('Asia/Manila', $this->as('analyst@example.com')->getJson('/api/v1/analytics/charts')->json('timezone'));
    }

    public function test_the_dashboard_returns_only_what_each_role_may_see(): void
    {
        $this->paid(10000);
        $money = ['gmv_amount', 'captured_amount', 'net_revenue_amount', 'refund_rate'];
        $keys = fn (string $email) => $this->as($email)->getJson('/api/v1/dashboard')->assertOk()->json();

        $analyst = $keys('analyst@example.com');
        foreach ([...$money, 'open_tickets', 'sla_compliance', 'shipments_in_transit', 'on_time_rate', 'active_subscriptions'] as $key) { $this->assertArrayHasKey($key, $analyst['summary']); }
        $this->assertSame([], $analyst['activity']);

        $support = $keys('support.one@example.com');
        foreach ($money as $key) { $this->assertArrayNotHasKey($key, $support['summary']); }
        $this->assertArrayNotHasKey('active_subscriptions', $support['summary']);
        $this->assertArrayHasKey('open_tickets', $support['summary']);

        $logistics = $keys('logistics@example.com');
        $this->assertArrayNotHasKey('gmv_amount', $logistics['summary']);
        $this->assertArrayNotHasKey('payment_status', $logistics['distributions']);
        $this->assertArrayHasKey('shipments_in_transit', $logistics['summary']);

        $finance = $keys('finance@example.com');
        $this->assertSame(10000, $finance['summary']['captured_amount']);
        $this->assertArrayHasKey('active_subscriptions', $finance['summary']);

        $this->as('admin@example.com')->putJson('/api/v1/settings', ['company_name' => 'Archon Nell'])->assertOk();
        $this->assertNotEmpty($keys('admin@example.com')['activity'], 'Only audit-log readers see recent activity.');
    }

    public function test_needs_attention_counts_only_what_each_role_may_open_and_hides_zero_counts(): void
    {
        $payment = $this->paid(10000);
        Refund::create(['payment_id' => $payment->id, 'total_amount' => 5000, 'reason' => 'other']);
        Payment::whereKey($this->paid(20000)->id)->update(['status' => 'authorized', 'risk_level' => 'review']);
        $ticket = ['subject' => 'Help', 'description' => 'Please help.', 'source_system' => 'orders', 'channel' => 'email', 'category' => 'order', 'priority' => 'normal', 'requester_type' => 'customer', 'requester_id' => $payment->customer_id, 'first_response_due_at' => now()->addHour(), 'resolution_due_at' => now()->addDay()];
        $this->seed(LogisticsDemoSeeder::class); $hub = Warehouse::where('code', 'demo-las-pinas-hub')->firstOrFail();
        $shipment = Shipment::create(['order_id' => $payment->order_id, 'seller_id' => $payment->order->items()->value('seller_id'), 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $hub->id, 'destination_address_id' => $hub->address_id, 'weight_grams' => 1000, 'dimensions' => [], 'shipping_fee_amount' => 0, 'promised_from' => now(), 'promised_to' => now()->addDays(3)]);
        Ticket::create($ticket);
        Ticket::create([...$ticket, 'shipment_id' => $shipment->id, 'assignee_id' => User::where('email', 'support.two@example.com')->value('id'), 'first_response_due_at' => now()->subHour()]);
        Ticket::create([...$ticket, 'status' => 'resolved', 'first_response_due_at' => now()->subHour()]);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'attention-seller', 'shop_name' => 'Attention shop']);
        $plan = Plan::create(['name' => 'Attention', 'code' => 'attention', 'monthly_price_amount' => 100, 'annual_price_amount' => 1000, 'features' => [], 'limits' => []]);
        Subscription::create(['seller_id' => $seller->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'status' => 'past_due', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        Settlement::create(['seller_id' => $seller->id, 'period_start' => now()->subWeek(), 'period_end' => now()]);
        $import = Reconciliation::create(['filename' => 'a.csv', 'mime_type' => 'text/csv', 'size' => 1, 'content' => '', 'date_from' => now(), 'date_to' => now(), 'created_by' => User::where('email', 'admin@example.com')->value('id')]);
        foreach ([['unmatched', null], ['matched', null], ['unmatched', now()]] as $i => [$status, $resolved]) { $import->items()->create(['date' => now(), 'gateway_reference' => "ref-$i", 'total_amount' => 100, 'status' => $status, 'resolved_at' => $resolved]); }
        $client = IntegrationClient::create(['name' => 'Shop', 'system_code' => 'attention']);
        $client->createToken('soon', ['*'], now()->addDays(3)); $client->createToken('later', ['*'], now()->addDays(30));
        $endpoint = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://example.com/hook', 'events' => [], 'secret' => 'secret', 'is_active' => false, 'disabled_at' => now()]);
        WebhookDelivery::create(['id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id, 'event_id' => 'evt_1', 'event_type' => 'payment.captured', 'request_body' => '{}', 'status' => 'failed', 'attempt_log' => []]);
        LoginLockout::create(['email' => 'finance@example.com', 'failed_attempts' => 6, 'locked_until' => now()->addMinutes(5), 'last_failed_at' => now()]);
        SchedulerLock::create(['name' => 'scheduled-work', 'last_run_at' => now()->subHours(27), 'last_result' => ['ran' => true, 'failed_jobs' => ['billing']]]);
        $attention = function (string $email) { $body = $this->as($email)->getJson('/api/v1/dashboard')->assertOk()->json(); return [collect($body['attention'])->pluck('count', 'key')->all(), array_key_exists('scheduler', $body)]; };

        $finance = ['refunds_requested' => 1, 'risk_reviews' => 1, 'unassigned_tickets' => 1, 'breached_tickets' => 1, 'past_due_subscriptions' => 1, 'draft_settlements' => 1, 'unresolved_reconciliation' => 1];
        $this->assertSame([['failed_deliveries' => 1, 'disabled_endpoints' => 1, 'expiring_tokens' => 1, 'locked_signins' => 1, ...$finance, 'scheduler_stale' => 1, 'scheduler_failed_jobs' => 1], true], $attention('admin@example.com'));
        $this->assertSame([$finance, false], $attention('finance@example.com'));
        $this->assertSame([['unassigned_tickets' => 1], false], $attention('support.one@example.com'), 'Another agent\'s breached ticket is hidden; a zero count is left out.');
        $this->assertSame([['breached_tickets' => 1], false], $attention('logistics@example.com'), 'Coordinators count only shipment tickets.');
        $this->assertSame([[], false], $attention('analyst@example.com'));

        SchedulerLock::whereKey('scheduled-work')->update(['last_run_at' => now(), 'last_result' => json_encode(['ran' => true, 'failed_jobs' => []])]);
        Cache::flush();
        $this->assertArrayNotHasKey('scheduler_stale', $attention('admin@example.com')[0]);
    }

    public function test_csv_exports_neutralize_spreadsheet_formulas_but_keep_numbers(): void
    {
        foreach (['=SUM(A1)' => "'=SUM(A1)", '@cmd' => "'@cmd", '-2+3' => "'-2+3", "\tx" => "'\tx", '-150' => '-150', '+1' => '+1', 'Plain' => 'Plain', null => ''] as $value => $expected) {
            $this->assertSame($expected, ListResponse::cell($value === '' ? null : $value));
        }
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'formula-seller', 'shop_name' => '=HYPERLINK("https://example.com","x")']);
        $this->paid(10000, 'orders', $seller, 'card', '@formula customer');
        $this->assertStringContainsString("'=HYPERLINK", $this->as('analyst@example.com')->get('/api/v1/analytics/export?report=top_sellers')->streamedContent());
        $this->assertStringContainsString("'@formula customer", $this->as('admin@example.com')->get('/api/v1/payments?export=csv')->streamedContent());
    }

    public function test_integration_summaries_include_only_the_clients_own_source_system(): void
    {
        $this->paid(10000, 'orders');
        $this->paid(70000, 'other-system');
        $token = IntegrationClient::create(['name' => 'Orders', 'system_code' => 'orders', 'is_active' => true])->createToken('analytics', ['analytics:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/integrations/analytics/summary')->assertOk()->assertJsonPath('gmv_amount', 10000)->assertJsonPath('captured_amount', 10000);
    }
}