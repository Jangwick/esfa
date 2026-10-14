<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\CommissionRule;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Rate;
use App\Models\ReconciliationItem;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Zone;
use App\Services\PaymentService;
use App\Services\Scheduler;
use App\Services\WebhookService;
use Carbon\CarbonImmutable;
use Database\Seeders\IntegrationDemoSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $adminToken;
    private string $financeToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['sfa.simulator' => true, 'sfa.partner_url' => 'http://localhost:8787']);
        Http::preventStrayRequests();
        CommissionRule::create(['name' => 'Test global rule', 'scope' => 'global', 'rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subDay()]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'simulator.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $this->admin->assignRole('Admin');
        $this->adminToken = $this->admin->createToken('staff', ['staff'])->plainTextToken;
        $finance = User::create(['name' => 'Finance', 'email' => 'simulator.finance@example.com', 'password' => 'password', 'is_active' => true]);
        $finance->assignRole('Finance');
        $this->financeToken = $finance->createToken('staff', ['staff'])->plainTextToken;
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token);
    }

    private function asAdmin(): self
    {
        return $this->as($this->adminToken);
    }

    private function connect(): array
    {
        Http::fake(['localhost:8787/*' => Http::response(['ok' => true])]);

        return $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertOk()->json();
    }

    // The simulated storefront signs up a customer and a seller through the real integration API.
    private function storefront(): string
    {
        $token = $this->connect()['systems']['storefront']['token'];
        $this->as($token)->putJson('/api/v1/integrations/customers/cus-juan01', ['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com'])->assertCreated();
        $this->as($token)->putJson('/api/v1/integrations/sellers/sel-sari01', ['shop_name' => 'Sari-Sari Shop', 'owner_name' => 'Maria Santos'])->assertCreated();

        return $token;
    }

    private function orderPayload(int $unitPrice = 50000, string $status = 'placed'): array
    {
        return ['customer_external_id' => 'cus-juan01', 'status_snapshot' => $status, 'subtotal_amount' => $unitPrice * 2, 'shipping_amount' => 10000, 'discount_amount' => 5000, 'total_amount' => $unitPrice * 2 + 5000, 'placed_at' => now()->toIso8601String(),
            'items' => [['seller_external_id' => 'sel-sari01', 'product_name' => 'Rice cooker', 'quantity' => 2, 'unit_price_amount' => $unitPrice, 'line_total_amount' => $unitPrice * 2]]];
    }

    private function order(string $token, string $externalId, int $unitPrice = 50000): array
    {
        return $this->as($token)->putJson('/api/v1/integrations/orders/'.$externalId, $this->orderPayload($unitPrice))->assertCreated()->json();
    }

    private function pay(string $token, string $order, string $method): TestResponse
    {
        return $this->as($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/integrations/payments', ['order_external_id' => $order, 'method' => $method]);
    }

    // The Logistics Coordinator books a parcel for the order with the demo courier (needs LogisticsDemoSeeder).
    private function book(string $order, ?string $tracking): array
    {
        $city = City::where('code', '1380200000')->firstOrFail();
        $destination = ['line1' => 'Demo address', 'region_id' => $city->region_id, 'city_id' => $city->id, 'barangay_id' => Barangay::where('code', '1380200001')->value('id'), 'postal_code' => '1740', 'contact_name' => 'Juan Dela Cruz', 'contact_phone' => '09170000000'];

        return $this->asAdmin()->postJson('/api/v1/logistics/shipments', ['order_id' => Order::where('external_id', $order)->value('id'), 'seller_id' => Seller::where('external_id', 'sel-sari01')->value('id'), 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => Warehouse::where('code', 'demo-las-pinas-hub')->value('id'), 'destination' => $destination, 'weight_grams' => 1000, 'dimensions' => ['length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 0, 'tracking_number' => $tracking])->assertCreated()->json();
    }

    // A courier or gateway webhook, signed as the simulator signs it.
    private function signed(string $path, array $body, string $secret): TestResponse
    {
        $timestamp = now()->timestamp;
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withHeader('X-SFA-Signature', 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.json_encode($body), $secret))->postJson($path, $body);
    }

    private function courierEvent(string $tracking, string $status): TestResponse
    {
        return $this->signed('/api/v1/webhooks/couriers/demo-courier', ['id' => 'crr_'.Str::uuid(), 'tracking_number' => $tracking, 'status' => $status], Courier::where('code', 'demo-courier')->firstOrFail()->webhook_secret);
    }

    private function gatewayEvent(string $reference, string $type): TestResponse
    {
        return $this->signed('/api/v1/webhooks/payments/sandbox', ['event_id' => 'gw_'.Str::uuid(), 'reference' => $reference, 'type' => $type], 'gateway-secret');
    }

    // One row of a picker list, as GET /simulator lists it now.
    private function pickerRow(string $list, string $key, mixed $value): ?array
    {
        return collect(data_get($this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->json(), $list))->firstWhere($key, $value);
    }

    public function test_simulator_requires_a_signed_in_admin_and_can_be_switched_off(): void
    {
        $this->getJson('/api/v1/simulator')->assertUnauthorized();
        $this->postJson('/api/v1/simulator/connect')->assertUnauthorized();
        $this->as($this->financeToken)->getJson('/api/v1/simulator')->assertForbidden();
        $this->as($this->financeToken)->postJson('/api/v1/simulator/connect')->assertForbidden();
        $this->assertSame(0, IntegrationClient::count());
        // The in-system storefront stand-ins were replaced by the integration API.
        foreach (['customers', 'sellers', 'orders', 'partner/connect'] as $path) {
            $this->asAdmin()->postJson('/api/v1/simulator/'.$path, ['name' => 'Nope'])->assertNotFound();
        }
        config(['sfa.simulator' => false]);
        $this->asAdmin()->getJson('/api/v1/simulator')->assertNotFound();
        $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertNotFound();
        $this->asAdmin()->postJson('/api/v1/simulator/run-scheduled')->assertNotFound();
        $this->assertSame(0, IntegrationClient::count());
    }

    public function test_connect_gives_the_storefront_and_finance_systems_working_tokens_and_keeps_existing_secrets(): void
    {
        config(['payments.sandbox_webhook_secret' => 'gateway-secret']);
        Courier::create(['name' => 'Demo courier', 'code' => 'demo-courier', 'service_types' => ['standard'], 'is_active' => true, 'webhook_secret' => 'courier-kept']);
        $storefrontClient = IntegrationClient::create(['name' => 'Demo storefront', 'system_code' => 'demo-storefront', 'is_active' => false]);
        $endpoint = WebhookEndpoint::create(['client_id' => $storefrontClient->id, 'url' => 'http://localhost:8787/webhooks', 'events' => ['payment.captured'], 'secret' => 'whsec_kept', 'is_active' => false, 'consecutive_failures' => 7, 'disabled_at' => now()]);
        $storefrontClient->createToken('demo', ['kb:read']);

        $first = $this->connect();
        $this->assertSame(['url' => 'http://localhost:8787', 'online' => true], $first['receiver']);
        $this->assertSame(['code' => 'demo-courier', 'secret' => 'courier-kept'], $first['courier']);
        $this->assertSame(['secret' => 'gateway-secret'], $first['gateway']);
        $this->assertSame($endpoint->id, $first['webhook_endpoint_id']);
        $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, CarbonImmutable::parse($first['expires_at'])->timestamp, 60);
        foreach (['storefront' => 'demo-storefront', 'finance' => 'demo-finance-gl'] as $key => $code) {
            $this->assertSame($code, $first['systems'][$key]['client_code']);
            $this->assertSame(IntegrationDemoSeeder::CLIENTS[$code][1], $first['systems'][$key]['scopes']);
            $client = IntegrationClient::where('system_code', $code)->sole();
            $this->assertTrue($client->is_active);
            $this->assertSame($code === 'demo-finance-gl', $client->reads_all_financials);
            $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, $client->tokens()->where('name', 'simulator')->sole()->expires_at->timestamp, 60);
        }
        $this->assertSame(1, $storefrontClient->tokens()->where('name', 'demo')->count(), 'Other tokens of the client are kept.');
        $endpoint->refresh();
        $this->assertSame([true, 0, null, WebhookEndpoint::EVENTS, 'whsec_kept'], [$endpoint->is_active, $endpoint->consecutive_failures, $endpoint->disabled_at, $endpoint->events, $endpoint->secret]);

        // Each token works against the real integration API with its own system's scopes only.
        $storefront = $first['systems']['storefront']['token'];
        $finance = $first['systems']['finance']['token'];
        $this->as($storefront)->putJson('/api/v1/integrations/customers/cus-ana001', ['name' => 'Ana Reyes'])->assertCreated()->assertJsonPath('source_system', 'demo-storefront');
        $this->as($finance)->getJson('/api/v1/integrations/analytics/summary')->assertOk()->assertJsonPath('currency', 'PHP');
        $this->as($finance)->putJson('/api/v1/integrations/customers/cus-ana001', ['name' => 'Ana Reyes'])->assertForbidden();

        // The receiver got the storefront configuration server-to-server; the audit log keeps no token or secret.
        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:8787/api/setup' && $request['sfa_api_url'] === url('/api/v1')
            && $request['client_code'] === 'demo-storefront' && $request['token'] === $storefront && $request['webhook_secret'] === 'whsec_kept'
            && $request['courier_code'] === 'demo-courier' && $request['courier_secret'] === 'courier-kept' && $request['gateway_secret'] === 'gateway-secret');
        $audit = AuditLog::all()->toJson();
        foreach ([explode('|', $storefront)[1], explode('|', $finance)[1], 'whsec_kept', 'courier-kept', 'gateway-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }

        // Connecting again replaces each simulator token and keeps the endpoint, its secret and the courier secret.
        $second = $this->connect();
        $this->assertNotSame($storefront, $second['systems']['storefront']['token']);
        $this->as($storefront)->getJson('/api/v1/integrations/kb/articles')->assertUnauthorized();
        $this->as($second['systems']['storefront']['token'])->getJson('/api/v1/integrations/kb/articles')->assertOk();
        foreach (['demo-storefront', 'demo-finance-gl'] as $code) {
            $this->assertSame(1, IntegrationClient::where('system_code', $code)->sole()->tokens()->where('name', 'simulator')->count());
        }
        $this->assertSame([$endpoint->id, 1, 'whsec_kept'], [$second['webhook_endpoint_id'], WebhookEndpoint::count(), $endpoint->fresh()->secret]);
        $this->assertSame('courier-kept', $second['courier']['secret']);

        // The 12-hour simulator tokens are renewed on every connect, so they raise no token-expiry reminder or dashboard count.
        config(['sfa.scheduler_enabled' => true]);
        $this->assertSame(0, app(Scheduler::class)->run()['token_expiry_reminders']);
        $attention = collect($this->asAdmin()->getJson('/api/v1/dashboard')->assertOk()->json('attention'))->pluck('count', 'key');
        $this->assertSame(0, $attention['expiring_tokens'] ?? 0);
    }

    public function test_connect_works_without_a_receiver_and_creates_the_missing_secrets(): void
    {
        config(['payments.sandbox_webhook_secret' => null]);
        $courier = Courier::create(['name' => 'Demo courier', 'code' => 'demo-courier', 'service_types' => ['standard'], 'is_active' => true, 'webhook_secret' => '']);
        Http::fake(['localhost:8787/*' => fn () => throw new ConnectionException('down')]);
        $response = $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertOk()->assertJsonPath('receiver.online', false)->assertJsonPath('gateway', null)->json();
        $this->assertStringStartsWith('courier_', $response['courier']['secret']);
        $this->assertSame($response['courier']['secret'], $courier->fresh()->webhook_secret);
        $endpoint = WebhookEndpoint::findOrFail($response['webhook_endpoint_id']);
        $this->assertSame(['http://localhost:8787/webhooks', true], [$endpoint->url, $endpoint->is_active]);
        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{64}$/', $endpoint->secret);

        $courier->update(['is_active' => false]);
        $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertOk()->assertJsonPath('courier', null);
    }

    public function test_pickers_list_the_demo_storefront_records_in_the_integration_shape(): void
    {
        $this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->assertJsonPath('courier', null)->assertJsonPath('courier_shipments', [])->assertJsonPath('storefront.orders', []);
        $this->seed(LogisticsDemoSeeder::class);
        $token = $this->storefront();
        $this->as($token)->putJson('/api/v1/integrations/categories/cat-kitchen', ['name' => 'Kitchen'])->assertCreated();
        $payload = $this->orderPayload();
        $payload['items'][0]['category_external_id'] = 'cat-kitchen';
        foreach (['ord-261011-0001', 'ord-261011-0002', 'ord-261011-0003'] as $order) {
            $this->as($token)->putJson('/api/v1/integrations/orders/'.$order, $payload)->assertCreated();
        }
        $payment = $this->pay($token, 'ord-261011-0001', 'card')->assertCreated()->json('payment');
        Customer::create(['source_system' => 'other-store', 'external_id' => 'cus-other1', 'name' => 'Elsewhere buyer']);
        Seller::create(['source_system' => 'other-store', 'external_id' => 'sel-other1', 'shop_name' => 'Elsewhere shop']);
        $customer = Customer::where('external_id', 'cus-juan01')->value('id');
        $ticket = $this->asAdmin()->postJson('/api/v1/support/tickets', ['subject' => 'Where is my rice cooker?', 'description' => 'Not here yet.', 'requester_type' => 'customer', 'requester_id' => $customer, 'channel' => 'email', 'category' => 'delivery'])->assertCreated()->json('reference');

        // The Logistics Coordinator books the parcels in SFA: one moving, one cancelled, one without a tracking number.
        $moving = $this->book('ord-261011-0001', 'DEMO-SIM-0001');
        $cancelled = $this->book('ord-261011-0002', 'DEMO-SIM-0002');
        $this->asAdmin()->postJson('/api/v1/logistics/shipments/'.$cancelled['id'].'/events', ['status' => 'cancelled'])->assertOk();
        $untracked = $this->book('ord-261011-0003', null);
        $refund = $this->as($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/integrations/refunds', ['payment_reference' => $payment['reference'], 'amount' => 1000, 'reason' => 'other'])->assertCreated()->json();

        $data = $this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->assertJsonStructure([
            'partner_url',
            'storefront' => [
                'customers' => [['external_id', 'name', 'email', 'phone']], 'sellers' => [['external_id', 'shop_name', 'owner_name', 'email', 'phone', 'payout_destination_masked', 'payout_channel']], 'categories' => [['external_id', 'name', 'parent_external_id', 'parent_choices']],
                'orders' => [['external_id', 'status_snapshot', 'subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'placed_at', 'customer' => ['external_id', 'name'], 'items' => [['seller_external_id', 'category_external_id', 'product_name', 'sku', 'quantity', 'unit_price_amount', 'line_total_amount']], 'payments', 'statuses', 'actions', 'next_step']],
                'payments' => [['id', 'reference', 'status', 'method', 'amount', 'total_amount', 'captured_amount', 'refunded_amount', 'risk_level', 'expires_at', 'order_external_id', 'refundable_amount', 'actions', 'next_step']],
                'refunds' => [['id', 'reference', 'status', 'total_amount', 'payment_reference']],
                'tickets' => [['id', 'reference', 'subject', 'status', 'csat_score', 'order_external_id', 'payment_reference', 'actions', 'next_step']], 'shipments' => [['id', 'reference', 'tracking_number', 'status', 'order_external_id', 'seller_external_id']],
            ],
            'quote_cities' => [['code', 'name']],
            'finance_sellers' => [['external_id', 'shop_name', 'source_system']], 'courier' => ['code'], 'courier_shipments' => [['id', 'reference', 'tracking_number', 'status', 'attempt_count', 'actions', 'next_step']],
        ])->json();
        $this->assertSame('http://localhost:8787', $data['partner_url']);
        $this->assertSame([['external_id' => 'cus-juan01', 'name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'phone' => null]], $data['storefront']['customers']);
        $this->assertSame(['sel-sari01'], array_column($data['storefront']['sellers'], 'external_id'));
        $this->assertSame(['ord-261011-0003', 'ord-261011-0002', 'ord-261011-0001'], array_column($data['storefront']['orders'], 'external_id'));
        $this->assertSame([['reference' => $payment['reference'], 'status' => 'captured', 'method' => 'card']], $data['storefront']['orders'][2]['payments']);
        $this->assertSame([$payment['reference'], 'ord-261011-0001', 105000], [$data['storefront']['payments'][0]['reference'], $data['storefront']['payments'][0]['order_external_id'], $data['storefront']['payments'][0]['captured_amount']]);
        $this->assertSame([$ticket], array_column($data['storefront']['tickets'], 'reference'));
        $this->assertSame([$untracked['reference'], $cancelled['reference'], $moving['reference']], array_column($data['storefront']['shipments'], 'reference'));
        $this->assertSame([['external_id' => 'sel-other1', 'shop_name' => 'Elsewhere shop', 'source_system' => 'other-store'], ['external_id' => 'sel-sari01', 'shop_name' => 'Sari-Sari Shop', 'source_system' => 'demo-storefront']], $data['finance_sellers']);
        $this->assertSame(['code' => 'demo-courier'], $data['courier']);
        // Every parcel the courier has not finished is listed (the cancelled one is final), with what the courier may send.
        $this->assertSame([
            ['id' => $untracked['id'], 'reference' => $untracked['reference'], 'tracking_number' => null, 'status' => 'pending', 'attempt_count' => 0, 'actions' => [], 'next_step' => 'Add the courier tracking number in Logistics & Locations (shipment details, Edit shipment), then mark it Ready for pickup.'],
            ['id' => $moving['id'], 'reference' => $moving['reference'], 'tracking_number' => 'DEMO-SIM-0001', 'status' => 'pending', 'attempt_count' => 0, 'actions' => [], 'next_step' => 'Mark it Ready for pickup in Logistics & Locations.'],
        ], $data['courier_shipments']);
        // What each record can take now: a paid order only a status update, unpaid ones a cancel and a payment as well.
        $this->assertSame([['status_update', 'cancel', 'payment'], ['status_update', 'cancel', 'payment'], ['status_update']], array_column($data['storefront']['orders'], 'actions'));
        $this->assertSame([$payment['id'], ['payment.disputed', 'refund'], 104000, null], [$data['storefront']['payments'][0]['id'], $data['storefront']['payments'][0]['actions'], $data['storefront']['payments'][0]['refundable_amount'], $data['storefront']['payments'][0]['next_step']]);
        $this->assertSame([['id' => $refund['id'], 'reference' => $refund['reference'], 'status' => 'requested', 'total_amount' => 1000, 'payment_reference' => $payment['reference']]], $data['storefront']['refunds']);
        $this->assertSame([['reply'], null], [$data['storefront']['tickets'][0]['actions'], $data['storefront']['tickets'][0]['csat_score']]);

        // An order from the pickers can be sent back unchanged with a new status, as the storefront's status update does.
        $order = $data['storefront']['orders'][2];
        $this->as($token)->putJson('/api/v1/integrations/orders/'.$order['external_id'], ['customer_external_id' => $order['customer']['external_id'], 'status_snapshot' => 'shipped', 'placed_at' => $order['placed_at']]
            + array_intersect_key($order, array_flip(['subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'items'])))->assertOk()->assertJsonPath('status_snapshot', 'shipped');
    }

    // What the Store profile, help-center ticket and refund forms prefill: the seller's profile as SFA holds it, each
    // parcel's order and seller, and the order and payment a ticket is about.
    public function test_pickers_carry_seller_profiles_and_the_links_between_records(): void
    {
        $this->seed(LogisticsDemoSeeder::class);
        $token = $this->storefront();
        $this->as($token)->putJson('/api/v1/integrations/sellers/sel-sari01', ['shop_name' => 'Sari-Sari Shop', 'owner_name' => 'Maria Santos', 'email' => 'maria@example.com', 'phone' => '09171234567'])->assertOk();
        $this->order($token, 'ord-261011-0700');
        $payment = $this->pay($token, 'ord-261011-0700', 'card')->assertCreated()->json('payment.reference');
        $parcel = $this->book('ord-261011-0700', 'DEMO-SIM-0700');
        $ticket = fn (array $values) => $this->as($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/integrations/tickets', $values + ['description' => 'Please check.', 'channel' => 'web_form', 'requester_type' => 'customer', 'requester_external_id' => 'cus-juan01'])->assertCreated()->json('reference');
        $linked = $ticket(['subject' => 'Charged twice', 'category' => 'payment', 'order_external_id' => 'ord-261011-0700', 'payment_reference' => $payment]);
        $plain = $ticket(['subject' => 'How do I order?', 'category' => 'other']);

        $data = $this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->json('storefront');
        $this->assertSame([['external_id' => 'sel-sari01', 'shop_name' => 'Sari-Sari Shop', 'owner_name' => 'Maria Santos', 'email' => 'maria@example.com', 'phone' => '09171234567', 'payout_destination_masked' => null, 'payout_channel' => null]], $data['sellers']);
        $shipment = collect($data['shipments'])->firstWhere('id', $parcel['id']);
        $this->assertSame(['ord-261011-0700', 'sel-sari01'], [$shipment['order_external_id'], $shipment['seller_external_id']]);
        $tickets = collect($data['tickets'])->keyBy('reference');
        $this->assertSame(['ord-261011-0700', $payment], [$tickets[$linked]['order_external_id'], $tickets[$linked]['payment_reference']]);
        $this->assertSame([null, null], [$tickets[$plain]['order_external_id'], $tickets[$plain]['payment_reference']]);
    }

    // The Shipping quote form offers only the cities SFA can quote between: those in a zone of a rate quotes() would use.
    public function test_quote_cities_follow_the_rates_sfa_can_quote(): void
    {
        $cities = fn () => array_column($this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->json('quote_cities'), 'name', 'code');
        $quote = fn (string $from, string $to) => $this->asAdmin()->postJson('/api/v1/logistics/quotes', ['origin_code' => $from, 'destination_code' => $to, 'weight_grams' => 500, 'dimensions' => ['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10], 'cod_amount' => 0])->assertOk()->json('data');
        $this->assertSame([], $cities());
        $this->seed(LogisticsDemoSeeder::class);
        $ncr = ['1380200000' => 'City of Las Piñas', '1380300000' => 'City of Makati'];
        $this->assertSame($ncr, $cities());
        $this->assertNotEmpty($quote('1380200000', '1380300000'));

        // A zone covering a whole province brings in its cities; a city outside every usable zone stays out.
        $region = Region::create(['code' => '0400000000', 'name' => 'CALABARZON']);
        $cavite = Province::create(['code' => '0402100000', 'name' => 'Cavite', 'region_id' => $region->id]);
        City::create(['code' => '0402103000', 'name' => 'City of Bacoor', 'region_id' => $region->id, 'province_id' => $cavite->id, 'type' => 'city']);
        City::create(['code' => '0405624000', 'name' => 'City of Lucena', 'region_id' => $region->id, 'province_id' => null, 'type' => 'city']);
        $courier = Courier::where('code', 'demo-courier')->sole();
        $south = Zone::create(['name' => 'Cavite', 'province_ids' => [$cavite->id], 'city_ids' => [], 'min_days' => 2, 'max_days' => 4, 'is_active' => true]);
        $rate = Rate::create(['courier_id' => $courier->id, 'service_type' => 'standard', 'origin_zone_id' => Zone::where('name', 'Demo NCR route')->value('id'), 'destination_zone_id' => $south->id, 'base_weight_grams' => 1000, 'base_fee_amount' => 10000, 'additional_per_500g_amount' => 1500, 'cod_fee_bp' => 100, 'max_weight_grams' => 20000, 'is_active' => true]);
        $this->assertSame(['0402103000' => 'City of Bacoor'] + $ncr, $cities());
        $this->assertNotEmpty($quote('1380200000', '0402103000'));

        // A rate SFA would not use adds nothing: an inactive zone, a service the courier stopped offering, an inactive rate.
        $south->update(['is_active' => false]);
        $this->assertSame($ncr, $cities());
        $south->update(['is_active' => true]);
        $courier->update(['service_types' => ['express']]);
        $this->assertSame($ncr, $cities());
        $this->assertSame([], $quote('1380200000', '0402103000'));
        $courier->update(['service_types' => ['standard', 'express']]);
        $rate->update(['is_active' => false]);
        $this->assertSame($ncr, $cities());
        // Without an active courier nothing can be quoted.
        $courier->update(['is_active' => false]);
        $this->assertSame([], $cities());
    }

    // Coverage of the removed in-system checkout, now through the storefront's integration token.
    public function test_storefront_orders_are_validated_and_stay_within_their_own_source_system(): void
    {
        $token = $this->storefront();
        $this->as($token)->putJson('/api/v1/integrations/customers/cus-empty', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->as($token)->putJson('/api/v1/integrations/orders/ord-bad-0001', ['items' => []] + $this->orderPayload())->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->as($token)->putJson('/api/v1/integrations/orders/ord-bad-0002', ['total_amount' => 1] + $this->orderPayload())->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        Seller::create(['source_system' => 'other-store', 'external_id' => 'sel-other1', 'shop_name' => 'Elsewhere']);
        $foreign = $this->orderPayload();
        $foreign['items'][0]['seller_external_id'] = 'sel-other1';
        $this->as($token)->putJson('/api/v1/integrations/orders/ord-bad-0003', $foreign)->assertNotFound();
        $this->order($token, 'ord-good-0001');
        $this->pay($token, 'ord-good-0001', 'cheque')->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->assertSame(0, Payment::count());
    }

    public function test_storefront_checkout_captures_the_payment_and_the_records_show_in_the_pickers(): void
    {
        $token = $this->storefront();
        $order = $this->order($token, 'ord-261011-0100');
        $this->assertSame([100000, 105000, 'demo-storefront'], [$order['subtotal_amount'], $order['total_amount'], $order['source_system']]);
        $payment = $this->pay($token, 'ord-261011-0100', 'card')->assertCreated()->json('payment');
        $this->assertSame('captured', $payment['status']);
        $this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->assertJsonPath('storefront.orders.0.payments.0.status', 'captured');
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0100/cancel')->assertUnprocessable()->assertJsonValidationErrors('order');

        $today = now('Asia/Manila')->toDateString();
        $seller = Seller::where('external_id', 'sel-sari01')->value('id');
        $settlement = $this->asAdmin()->postJson('/api/v1/settlements/generate', ['period_start' => $today, 'period_end' => $today, 'seller_id' => $seller])->assertCreated()->json('data.0.id');
        $this->assertSame($settlement, Notification::where('user_id', $this->admin->id)->where('type', 'settlement_ready')->firstOrFail()->data['settlement_id']);
    }

    public function test_cash_on_delivery_waits_and_an_unpaid_storefront_order_can_be_cancelled(): void
    {
        $token = $this->storefront();
        $this->order($token, 'ord-261011-0200');
        $payment = $this->pay($token, 'ord-261011-0200', 'cod')->assertCreated()->json('payment');
        $this->assertSame('pending', $payment['status']);
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0200/cancel')->assertOk()->assertJsonPath('status_snapshot', 'cancelled');
        $this->assertSame('cancelled', Payment::where('reference', $payment['reference'])->value('status'));
        // A repeated cancel changes nothing, and a cancelled order takes no new payment.
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0200/cancel')->assertOk()->assertJsonPath('status_snapshot', 'cancelled');
        $this->pay($token, 'ord-261011-0200', 'card')->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(1, Payment::count());
    }

    public function test_scheduled_work_can_be_run_on_demand(): void
    {
        $this->asAdmin()->postJson('/api/v1/simulator/run-scheduled')->assertOk()->assertJsonStructure(['ran']);
    }

    public function test_sample_settlement_file_produces_every_reconciliation_outcome(): void
    {
        $token = $this->storefront();
        foreach ([50000, 60000, 70000] as $index => $price) {
            $this->order($token, 'ord-261011-030'.$index, $price);
            $this->pay($token, 'ord-261011-030'.$index, 'gcash')->assertCreated()->assertJsonPath('payment.status', 'captured');
        }
        $today = now('Asia/Manila')->toDateString();
        $this->asAdmin()->getJson("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertOk();
        $this->asAdmin()->getJson('/api/v1/simulator/settlement-file?date_from=2020-01-01&date_to=2020-01-02')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $csv = $this->asAdmin()->get("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("date,gateway_reference,amount,fee\n", $csv);
        $this->assertStringContainsString('sandbox_UNKNOWN-', $csv);

        $file = UploadedFile::fake()->createWithContent('settlement.csv', $csv);
        // A period that does not cover the file's dates names the row, its date and the period.
        $yesterday = now('Asia/Manila')->subDay()->toDateString();
        $this->asAdmin()->post('/api/v1/reconciliations', ['file' => $file, 'date_from' => $yesterday, 'date_to' => $yesterday], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('errors.file.0', "CSV row 2 is dated {$today}, outside the period {$yesterday} to {$yesterday}. Import it with a period that covers every row.");
        $items =$this->asAdmin()->post('/api/v1/reconciliations', ['file' => $file, 'date_from' => $today, 'date_to' => $today], ['Accept' => 'application/json'])->assertCreated()->json('items');
        $this->assertEqualsCanonicalizing(['matched', 'amount_mismatch', 'missing_in_report', 'missing_in_sfa'], array_column($items, 'status'));

        // A later file for the same period reports the omitted payment, which resolves the earlier row automatically.
        $late = $this->asAdmin()->get("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertOk()->getContent();
        $later = $this->asAdmin()->post('/api/v1/reconciliations', ['file' => UploadedFile::fake()->createWithContent('later.csv', $late), 'date_from' => $today, 'date_to' => $today], ['Accept' => 'application/json'])->assertCreated()->json('items');
        $this->assertNotContains('missing_in_report', array_column($later, 'status'));
        $missing = collect($items)->firstWhere('status', 'missing_in_report');
        $this->assertNotNull(ReconciliationItem::findOrFail($missing['id'])->resolved_at);
    }

    public function test_the_customer_side_of_a_ticket_can_be_simulated(): void
    {
        $this->storefront();
        $customer = Customer::where('external_id', 'cus-juan01')->value('id');
        $ticket = $this->asAdmin()->postJson('/api/v1/support/tickets', ['subject' => 'Where is my rice cooker?', 'description' => 'It has not arrived yet.', 'requester_type' => 'customer', 'requester_id' => $customer, 'channel' => 'email', 'category' => 'delivery'])->assertCreated()->json('id');
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/reply", [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/reply", ['body' => 'Any update?'])->assertCreated()->assertJsonPath('author_type', 'customer')->assertJsonPath('is_internal_note', false);
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/rating", ['score' => 5])->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->asAdmin()->postJson("/api/v1/support/tickets/{$ticket}/status", ['status' => 'in_progress'])->assertOk();
        $this->asAdmin()->postJson("/api/v1/support/tickets/{$ticket}/status", ['status' => 'resolved'])->assertOk();
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/rating", ['score' => 9])->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/rating", ['score' => 5, 'comment' => 'Fast help'])->assertOk()->assertJsonPath('csat_score', 5);
        $this->as($this->financeToken)->postJson("/api/v1/simulator/tickets/{$ticket}/reply", ['body' => 'Hi'])->assertForbidden();
        $this->asAdmin()->getJson('/api/v1/user')->assertOk()->assertJsonPath('simulator_enabled', true);
    }

    public function test_a_simulated_customer_reply_moves_a_waiting_ticket_back_to_in_progress(): void
    {
        $this->storefront();
        $customer = Customer::where('external_id', 'cus-juan01')->value('id');
        $ticket = $this->asAdmin()->postJson('/api/v1/support/tickets', ['subject' => 'Wrong size', 'description' => 'The shirt is too small.', 'requester_type' => 'customer', 'requester_id' => $customer, 'channel' => 'email', 'category' => 'order'])->assertCreated()->json('id');
        $this->asAdmin()->postJson("/api/v1/support/tickets/{$ticket}/messages", ['body' => 'Which size do you need?', 'status' => 'waiting_on_customer'])->assertCreated();
        $this->asAdmin()->getJson("/api/v1/support/tickets/{$ticket}")->assertOk()->assertJsonPath('status', 'waiting_on_customer');
        $this->asAdmin()->postJson("/api/v1/simulator/tickets/{$ticket}/reply", ['body' => 'Medium, please.'])->assertCreated();
        $this->asAdmin()->getJson("/api/v1/support/tickets/{$ticket}")->assertOk()->assertJsonPath('status', 'in_progress');
    }

    // Each courier parcel lists exactly the reports SFA's courier webhook accepts, every listed report is accepted, and every
    // other report the simulated courier could make is refused.
    public function test_courier_actions_follow_the_parcel_and_each_one_is_accepted(): void
    {
        $this->seed(LogisticsDemoSeeder::class);
        $token = $this->storefront();
        $this->order($token, 'ord-261011-0400');
        $parcel = $this->book('ord-261011-0400', 'DEMO-SIM-0400');
        $check = function (array $actions) use ($parcel): array {
            $row = $this->pickerRow('courier_shipments', 'id', $parcel['id']);
            $this->assertSame($actions, $row['actions'], 'Actions for a '.$row['status'].' parcel');
            foreach (array_diff(['picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt', 'returned_to_sender'], $actions) as $status) {
                $this->courierEvent('DEMO-SIM-0400', $status)->assertUnprocessable();
            }

            return $row;
        };
        $send = fn (string $status, string $now) => $this->courierEvent('DEMO-SIM-0400', $status)->assertOk()->assertJsonPath('shipment.status', $now);

        $this->assertSame('Mark it Ready for pickup in Logistics & Locations.', $check([])['next_step']);
        $this->asAdmin()->postJson('/api/v1/logistics/shipments/'.$parcel['id'].'/events', ['status' => 'ready_for_pickup'])->assertOk();
        $this->assertNull($check(['picked_up'])['next_step']);
        $send('picked_up', 'picked_up');
        $check(['in_transit']);
        $send('in_transit', 'in_transit');
        foreach ([1, 2] as $attempt) {
            $check(['out_for_delivery']);
            $send('out_for_delivery', 'out_for_delivery');
            $check(['failed_attempt']);
            $send('failed_attempt', 'failed_attempt');
            $this->assertSame($attempt, $this->pickerRow('courier_shipments', 'id', $parcel['id'])['attempt_count']);
        }
        $check(['out_for_delivery']);
        $send('out_for_delivery', 'out_for_delivery');
        // Delivered is never offered: it needs proof of delivery. The third failed attempt starts the return.
        $check(['failed_attempt']);
        $send('failed_attempt', 'returning');
        $check(['returned_to_sender']);
        $send('returned_to_sender', 'returned_to_sender');
        $this->assertNull($this->pickerRow('courier_shipments', 'id', $parcel['id']), 'A returned parcel is final and leaves the list.');

        // With Fill skipped courier steps on, a pending parcel takes any forward report and SFA records the steps between.
        $this->asAdmin()->putJson('/api/v1/settings', ['logistics_courier_fill_gaps' => true])->assertOk();
        $this->order($token, 'ord-261011-0401');
        $jump = $this->book('ord-261011-0401', 'DEMO-SIM-0401');
        $this->assertSame(['picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt'], $this->pickerRow('courier_shipments', 'id', $jump['id'])['actions']);
        $this->courierEvent('DEMO-SIM-0401', 'failed_attempt')->assertOk()->assertJsonPath('shipment.status', 'failed_attempt')->assertJsonPath('shipment.attempt_count', 1);
        $this->assertSame(['out_for_delivery'], $this->pickerRow('courier_shipments', 'id', $jump['id'])['actions']);
        // A parcel without a tracking number cannot be named by the courier at all.
        $this->order($token, 'ord-261011-0402');
        $untracked = $this->book('ord-261011-0402', null);
        $this->assertSame([], $this->pickerRow('courier_shipments', 'id', $untracked['id'])['actions']);
    }

    // The gateway events listed for a payment are exactly the ones the signed sandbox webhook applies, for every status.
    public function test_gateway_actions_match_what_the_sandbox_webhook_applies(): void
    {
        config(['payments.sandbox_webhook_secret' => 'gateway-secret']);
        $this->storefront();
        $customer = Customer::where('external_id', 'cus-juan01')->value('id');
        $count = 0;
        $make = function (string $status, string $risk = 'none', string $method = 'card', bool $siblingCaptured = false) use ($customer, &$count): Payment {
            $count++;
            $order = Order::create(['source_system' => 'demo-storefront', 'external_id' => 'ord-gw-'.$count, 'customer_id' => $customer, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
            $captured = in_array($status, ['captured', 'partially_refunded', 'refunded', 'disputed', 'dispute_won', 'dispute_lost'], true) ? 10000 : 0;
            $payment = fn (string $reference, string $status, int $captured) => Payment::create(['reference' => $reference, 'order_id' => $order->id, 'customer_id' => $customer, 'method' => $method, 'gateway' => $method === 'cod' ? 'cod' : 'sandbox', 'gateway_reference' => 'sandbox_'.$reference, 'total_amount' => 10000, 'captured_amount' => $captured, 'refunded_amount' => ['partially_refunded' => 1000, 'refunded' => 10000][$status] ?? 0, 'status' => $status, 'risk_level' => $risk]);
            if ($siblingCaptured) { $payment('PAY-GW-'.$count.'-A', 'captured', 10000); }

            return $payment('PAY-GW-'.$count, $status, $captured);
        };
        $cases = array_map(fn (string $status) => [$status], Payment::STATUSES);
        array_push($cases, ['pending', 'review'], ['authorized', 'review'], ['pending', 'none', 'cod'], ['pending', 'none', 'card', true]);
        foreach ($cases as $case) {
            $listed = $this->pickerRow('storefront.payments', 'reference', $make(...$case)->reference)['actions'];
            $events = array_values(array_diff($listed, ['refund']));
            foreach (array_keys(PaymentService::GATEWAY_EVENT_STATUSES) as $type) {
                $response = $this->gatewayEvent($make(...$case)->reference, $type);
                $this->assertSame(in_array($type, $events, true), $response->status() === 200, implode('/', $case).' '.$type.' answered '.$response->status().': '.$response->getContent());
            }
            if ($case === ['captured']) { $this->assertSame(['payment.disputed', 'refund'], $listed); }
            if ($case === ['pending', 'none', 'cod']) { $this->assertSame([], $listed); }
        }

        // SFA would still capture a payment whose order was cancelled, but never does so itself, so it is not offered.
        $cancelled = $make('pending');
        $cancelled->order->update(['status_snapshot' => 'cancelled']);
        $this->assertSame(['payment.failed', 'payment.cancelled'], $this->pickerRow('storefront.payments', 'reference', $cancelled->reference)['actions']);
        $this->gatewayEvent($cancelled->reference, 'payment.cancelled')->assertOk()->assertJsonPath('processed', true);
        $row = $this->pickerRow('storefront.payments', 'reference', $cancelled->reference);
        $this->assertSame([[], 'This payment is cancelled, so SFA takes no gateway result or refund for it.'], [$row['actions'], $row['next_step']]);
    }

    // Order, payment and refund actions as the storefront sends them: a listed action is accepted, an unlisted one refused.
    public function test_order_payment_and_refund_actions_are_accepted_when_sent(): void
    {
        $this->seed(LogisticsDemoSeeder::class);
        $token = $this->storefront();
        $actions = fn (string $order) => $this->pickerRow('storefront.orders', 'external_id', $order)['actions'];

        // Unpaid: everything. With cash on delivery waiting, no second payment; cancelling cancels the waiting payment.
        $this->order($token, 'ord-261011-0500');
        $this->assertSame(['status_update', 'cancel', 'payment'], $actions('ord-261011-0500'));
        $cod = $this->pay($token, 'ord-261011-0500', 'cod')->assertCreated()->json('payment');
        $this->assertSame(['status_update', 'cancel'], $actions('ord-261011-0500'));
        $row = $this->pickerRow('storefront.payments', 'reference', $cod['reference']);
        $this->assertSame([[], 0], [$row['actions'], $row['refundable_amount']]);
        $this->assertStringStartsWith('Cash on delivery', $row['next_step']);
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0500/cancel')->assertOk();
        $order = $this->pickerRow('storefront.orders', 'external_id', 'ord-261011-0500');
        $this->assertSame([[], 'This order is cancelled, so SFA takes nothing more for it. Send a new order instead.'], [$order['actions'], $order['next_step']]);
        $this->pay($token, 'ord-261011-0500', 'card')->assertUnprocessable();

        // Paid: only a status update; the refund takes at most what is not yet refunded or waiting.
        $this->order($token, 'ord-261011-0501');
        $card = $this->pay($token, 'ord-261011-0501', 'card')->assertCreated()->json('payment');
        $this->assertSame(['status_update'], $actions('ord-261011-0501'));
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0501/cancel')->assertUnprocessable();
        $this->pay($token, 'ord-261011-0501', 'gcash')->assertUnprocessable();
        $paid = $this->pickerRow('storefront.orders', 'external_id', 'ord-261011-0501');
        $this->as($token)->putJson('/api/v1/integrations/orders/ord-261011-0501', ['customer_external_id' => 'cus-juan01', 'status_snapshot' => 'processing', 'placed_at' => $paid['placed_at']]
            + array_intersect_key($paid, array_flip(['subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'items'])))->assertOk();
        $refund = fn (int $amount) => $this->as($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/integrations/refunds', ['payment_reference' => $card['reference'], 'amount' => $amount, 'reason' => 'other']);
        $row = $this->pickerRow('storefront.payments', 'reference', $card['reference']);
        $this->assertSame([['payment.disputed', 'refund'], 105000], [$row['actions'], $row['refundable_amount']]);
        $refund(5000)->assertCreated();
        $row = $this->pickerRow('storefront.payments', 'reference', $card['reference']);
        $this->assertSame([['payment.disputed', 'refund'], 100000], [$row['actions'], $row['refundable_amount']]);
        $refund(100000)->assertCreated();
        $row = $this->pickerRow('storefront.payments', 'reference', $card['reference']);
        $this->assertSame([['payment.disputed'], 0], [$row['actions'], $row['refundable_amount']]);
        $refund(1)->assertUnprocessable()->assertJsonValidationErrors('amount');

        // A zero total takes no payment; a parcel already with the courier blocks the cancel.
        $free = ['customer_external_id' => 'cus-juan01', 'status_snapshot' => 'placed', 'subtotal_amount' => 0, 'shipping_amount' => 0, 'discount_amount' => 0, 'total_amount' => 0, 'placed_at' => now()->toIso8601String(), 'items' => [['seller_external_id' => 'sel-sari01', 'product_name' => 'Free sample', 'quantity' => 1, 'unit_price_amount' => 0, 'line_total_amount' => 0]]];
        $this->as($token)->putJson('/api/v1/integrations/orders/ord-261011-0502', $free)->assertCreated();
        $this->assertSame(['status_update', 'cancel'], $actions('ord-261011-0502'));
        $this->pay($token, 'ord-261011-0502', 'cod')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $parcel = $this->book('ord-261011-0502', 'DEMO-SIM-0502');
        foreach (['ready_for_pickup', 'picked_up'] as $status) {
            $this->asAdmin()->postJson('/api/v1/logistics/shipments/'.$parcel['id'].'/events', ['status' => $status])->assertOk();
        }
        $this->assertSame(['status_update'], $actions('ord-261011-0502'));
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-261011-0502/cancel')->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    // Order status update offers cancelled only while SFA cancels the order (no captured money, no parcel with the courier),
    // and every status it offers is accepted.
    public function test_order_status_update_offers_only_the_statuses_sfa_accepts(): void
    {
        $this->seed(LogisticsDemoSeeder::class);
        $token = $this->storefront();
        $statuses = fn (string $order) => $this->pickerRow('storefront.orders', 'external_id', $order)['statuses'];
        $update = function (string $order, string $status) use ($token) {
            $row = $this->pickerRow('storefront.orders', 'external_id', $order);

            return $this->as($token)->putJson('/api/v1/integrations/orders/'.$order, ['customer_external_id' => $row['customer']['external_id'], 'status_snapshot' => $status, 'placed_at' => $row['placed_at']]
                + array_intersect_key($row, array_flip(['subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'items'])));
        };

        // Captured money: no cancelled, and SFA indeed refuses it.
        $this->order($token, 'ord-261011-0800');
        $this->pay($token, 'ord-261011-0800', 'card')->assertCreated();
        $this->assertSame(['processing', 'packed', 'shipped', 'delivered', 'completed'], $statuses('ord-261011-0800'));
        $update('ord-261011-0800', 'cancelled')->assertUnprocessable()->assertJsonValidationErrors('order');
        foreach ($statuses('ord-261011-0800') as $status) {
            $update('ord-261011-0800', $status)->assertOk()->assertJsonPath('status_snapshot', $status);
        }
        $this->assertSame(['placed', 'processing', 'packed', 'shipped', 'delivered'], $statuses('ord-261011-0800'));

        // A parcel with the courier blocks it too.
        $this->order($token, 'ord-261011-0801');
        $parcel = $this->book('ord-261011-0801', 'DEMO-SIM-0801');
        $this->assertContains('cancelled', $statuses('ord-261011-0801'));
        foreach (['ready_for_pickup', 'picked_up'] as $status) {
            $this->asAdmin()->postJson('/api/v1/logistics/shipments/'.$parcel['id'].'/events', ['status' => $status])->assertOk();
        }
        $this->assertNotContains('cancelled', $statuses('ord-261011-0801'));
        $update('ord-261011-0801', 'cancelled')->assertUnprocessable();

        // Unpaid with a waiting cash-on-delivery payment: cancelled is offered and cancels the order and its payment.
        $this->order($token, 'ord-261011-0802');
        $cod = $this->pay($token, 'ord-261011-0802', 'cod')->assertCreated()->json('payment.reference');
        $this->assertSame(['processing', 'packed', 'shipped', 'delivered', 'completed', 'cancelled'], $statuses('ord-261011-0802'));
        $update('ord-261011-0802', 'cancelled')->assertOk()->assertJsonPath('status_snapshot', 'cancelled');
        $this->assertSame('cancelled', Payment::where('reference', $cod)->value('status'));
        $this->assertSame([], $statuses('ord-261011-0802'));
        $update('ord-261011-0802', 'processing')->assertUnprocessable()->assertJsonValidationErrors('status_snapshot');
    }

    // Choosing a category, customer or seller starts from what SFA holds; a category is offered only the parents SFA takes.
    public function test_pickers_show_the_saved_profile_parent_and_payout_destination(): void
    {
        $token = $this->storefront();
        $category = fn (string $id, array $body) => $this->as($token)->putJson('/api/v1/integrations/categories/'.$id, $body);
        $category('cat-home', ['name' => 'Home'])->assertCreated();
        $category('cat-kitchen', ['name' => 'Kitchen', 'parent_external_id' => 'cat-home'])->assertCreated();
        $category('cat-pots', ['name' => 'Pots', 'parent_external_id' => 'cat-kitchen'])->assertCreated();
        $category('cat-toys', ['name' => 'Toys'])->assertCreated();
        $rows = collect($this->asAdmin()->getJson('/api/v1/simulator')->assertOk()->json('storefront.categories'))->keyBy('external_id');
        $this->assertSame([null, ['cat-toys']], [$rows['cat-home']['parent_external_id'], $rows['cat-home']['parent_choices']]);
        $this->assertSame(['cat-home', ['cat-toys', 'cat-home']], [$rows['cat-kitchen']['parent_external_id'], $rows['cat-kitchen']['parent_choices']]);
        $this->assertSame(['cat-kitchen', ['cat-toys', 'cat-kitchen', 'cat-home']], [$rows['cat-pots']['parent_external_id'], $rows['cat-pots']['parent_choices']]);
        // Every parent offered is accepted, and the ones left out are refused.
        foreach ($rows['cat-home']['parent_choices'] as $parent) {
            $category('cat-home', ['name' => 'Home', 'parent_external_id' => $parent])->assertOk();
        }
        foreach (['cat-kitchen', 'cat-pots'] as $parent) {
            $category('cat-home', ['name' => 'Home', 'parent_external_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors('parent_external_id');
        }

        $this->as($token)->putJson('/api/v1/integrations/customers/cus-juan01', ['name' => 'Juan Dela Cruz', 'phone' => '09170000001'])->assertOk();
        $this->assertSame('09170000001', $this->pickerRow('storefront.customers', 'external_id', 'cus-juan01')['phone']);
        $seller = fn (array $payout) => $this->as($token)->putJson('/api/v1/integrations/sellers/sel-sari01', ['shop_name' => 'Sari-Sari Shop', 'payout_destination' => $payout])->assertOk();
        $seller(['bank_name' => 'BPI', 'account_name' => 'Maria Santos', 'account_number' => '001234567890']);
        $row = $this->pickerRow('storefront.sellers', 'external_id', 'sel-sari01');
        $this->assertSame(['•••• 7890', 'BPI'], [$row['payout_destination_masked'], $row['payout_channel']]);
        $seller(['bank_name' => 'GCash', 'account_name' => 'Maria Santos', 'number' => '09171234567']);
        $row = $this->pickerRow('storefront.sellers', 'external_id', 'sel-sari01');
        $this->assertSame(['•••• 4567', 'GCash'], [$row['payout_destination_masked'], $row['payout_channel']]);
    }

    // A requester may reply until the ticket is closed and rate it once it is resolved or closed.
    public function test_ticket_actions_are_accepted_when_sent(): void
    {
        $token = $this->storefront();
        $reference = $this->as($token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/integrations/tickets', ['subject' => 'Wrong colour', 'description' => 'I ordered blue.', 'channel' => 'web_form', 'category' => 'order', 'requester_type' => 'customer', 'requester_external_id' => 'cus-juan01'])->assertCreated()->json('reference');
        $row = fn () => $this->pickerRow('storefront.tickets', 'reference', $reference);
        $reply = fn () => $this->as($token)->postJson("/api/v1/integrations/tickets/{$reference}/messages", ['body' => 'Any news?']);
        $rate = fn () => $this->as($token)->postJson("/api/v1/integrations/tickets/{$reference}/csat", ['score' => 4]);
        $status = fn (string $status) => $this->asAdmin()->postJson('/api/v1/support/tickets/'.$row()['id'].'/status', ['status' => $status])->assertOk();

        $this->assertSame(['reply'], $row()['actions']);
        $reply()->assertCreated();
        $rate()->assertUnprocessable();
        $status('in_progress');
        $status('resolved');
        $this->assertSame(['reply', 'csat'], $row()['actions']);
        $rate()->assertOk();
        $reply()->assertCreated();
        $status('closed');
        $this->assertSame([['csat'], 4], [$row()['actions'], $row()['csat_score']]);
        $reply()->assertUnprocessable()->assertJsonValidationErrors('body');
        $rate()->assertOk();
        // The rating form starts from the score and comment SFA holds.
        $this->as($token)->postJson("/api/v1/integrations/tickets/{$reference}/csat", ['score' => 3, 'comment' => 'Slow but fixed.'])->assertOk();
        $this->assertSame([3, 'Slow but fixed.'], [$row()['csat_score'], $row()['csat_comment']]);
    }

    // Reconnecting once the receiver is up sends what waited for it: deliveries backing off and failed ones.
    public function test_connect_resends_the_webhooks_that_waited_for_the_receiver(): void
    {
        $up = false;
        Http::fake(['localhost:8787/*' => function () use (&$up) {
            if (! $up) { throw new ConnectionException('down'); }

            return Http::response(['ok' => true]);
        }]);
        $endpoint = $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertOk()->assertJsonPath('receiver.online', false)->json('webhook_endpoint_id');
        $webhooks = app(WebhookService::class);
        [$waiting] = $webhooks->emitFor('payment.captured', ['reference' => 'PAY-WAIT'], 'demo-storefront');
        [$failed] = $webhooks->emitFor('payment.failed', ['reference' => 'PAY-FAIL'], 'demo-storefront');
        $this->app->terminate();
        $failed->update(['status' => 'failed', 'next_attempt_at' => null]);
        $this->assertSame(['pending', true], [$waiting->fresh()->status, $waiting->fresh()->next_attempt_at->isFuture()]);

        $up = true;
        $this->asAdmin()->postJson('/api/v1/simulator/connect')->assertOk()->assertJsonPath('receiver.online', true);
        $this->assertSame(['delivered', 'delivered'], [$waiting->fresh()->status, $failed->fresh()->status]);
        $this->assertSame(0, WebhookDelivery::where('endpoint_id', $endpoint)->where('status', '!=', 'delivered')->count());
    }

    // A capture under ₱2.00 is reported half short instead of ₱1.00 short, so the short row keeps a positive amount and
    // still shows as an amount mismatch.
    public function test_settlement_file_short_row_keeps_a_positive_amount(): void
    {
        $token = $this->storefront();
        foreach ([0, 1, 2] as $index) {
            $this->as($token)->putJson('/api/v1/integrations/orders/ord-261011-060'.$index, ['customer_external_id' => 'cus-juan01', 'status_snapshot' => 'placed', 'subtotal_amount' => 50, 'shipping_amount' => 0, 'discount_amount' => 0, 'total_amount' => 50, 'placed_at' => now()->toIso8601String(), 'items' => [['seller_external_id' => 'sel-sari01', 'product_name' => 'Candy', 'quantity' => 1, 'unit_price_amount' => 50, 'line_total_amount' => 50]]])->assertCreated();
            $this->pay($token, 'ord-261011-060'.$index, 'gcash')->assertCreated()->assertJsonPath('payment.status', 'captured');
        }
        $today = now('Asia/Manila')->toDateString();
        $csv = $this->asAdmin()->get("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertOk()->getContent();
        $this->assertSame(['0.50', '0.25', '150.00'], array_map(fn (string $line) => str_getcsv($line)[2], array_slice(explode("\n", trim($csv)), 1)));
        $items = $this->asAdmin()->post('/api/v1/reconciliations', ['file' => UploadedFile::fake()->createWithContent('small.csv', $csv), 'date_from' => $today, 'date_to' => $today], ['Accept' => 'application/json'])->assertCreated()->json('items');
        $this->assertEqualsCanonicalizing(['matched', 'amount_mismatch', 'missing_in_report', 'missing_in_sfa'], array_column($items, 'status'));
    }

    // Cash on delivery is collected by the courier, so the gateway's settlement file never lists it.
    public function test_settlement_file_leaves_out_cash_on_delivery(): void
    {
        $token = $this->storefront();
        $this->order($token, 'ord-261011-0800');
        $card = Payment::where('reference', $this->pay($token, 'ord-261011-0800', 'card')->assertCreated()->json('payment.reference'))->sole();
        $this->order($token, 'ord-261011-0801');
        $cod = Payment::where('reference', $this->pay($token, 'ord-261011-0801', 'cod')->assertCreated()->json('payment.reference'))->sole();
        $cod->update(['status' => 'captured', 'captured_amount' => $cod->total_amount, 'captured_at' => now(), 'gateway_reference' => 'cod_SETTLE-0801']);
        $today = now('Asia/Manila')->toDateString();
        $csv = $this->asAdmin()->get("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertOk()->getContent();
        $this->assertStringContainsString($card->gateway_reference, $csv);
        $this->assertStringNotContainsString('cod_SETTLE-0801', $csv);
        // With only cash on delivery captured in the period there is no gateway file to make.
        $card->update(['captured_at' => now()->subYear()]);
        $this->asAdmin()->getJson("/api/v1/simulator/settlement-file?date_from={$today}&date_to={$today}")->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }

    // Like POST /automation/run, the on-demand run is limited to six a minute.
    public function test_running_scheduled_work_on_demand_is_throttled(): void
    {
        foreach (range(1, 6) as $run) {
            $this->asAdmin()->postJson('/api/v1/simulator/run-scheduled')->assertOk();
        }
        $this->asAdmin()->postJson('/api/v1/simulator/run-scheduled')->assertStatus(429);
    }

    public function test_simulator_mode_sends_webhooks_right_after_the_request(): void
    {
        $client = IntegrationClient::create(['name' => 'Demo storefront', 'system_code' => 'demo-storefront', 'is_active' => true]);
        $endpoint = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'http://localhost:8787/webhooks', 'events' => WebhookEndpoint::EVENTS, 'secret' => 'whsec_test', 'is_active' => true]);
        Http::fake(['localhost:8787/*' => Http::response(['ok' => true])]);
        $delivery = app(WebhookService::class)->emitFor('payment.captured', ['reference' => 'PAY-TEST'], 'demo-storefront')[0];
        $this->assertSame('pending', $delivery->fresh()->status);
        // The receive panels poll the cached delivery log; a delivery attempt must not leave it showing pending.
        $log = fn () => $this->asAdmin()->getJson('/api/v1/webhook-deliveries?endpoint_id='.$endpoint->id.'&per_page=50')->assertOk()->json('data.0.status');
        $this->assertSame('pending', $log());
        $this->app->terminate();
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $log());
        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:8787/webhooks' && $request->hasHeader('X-SFA-Signature') && $request->header('X-SFA-Event')[0] === 'payment.captured');
    }
}
