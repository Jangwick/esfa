<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\CommissionRule;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookEndpoint;
use App\Services\CommissionService;
use App\Services\LogisticsService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LogisticsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private string $token;
    private Order $order;
    private Seller $seller;
    private Warehouse $warehouse;
    private array $address;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed([RolePermissionSeeder::class, LogisticsDemoSeeder::class]);
        $this->admin = User::create(['name' => 'Logistics administrator', 'email' => 'logistics.test@example.com', 'password' => 'password', 'is_active' => true]); $this->admin->assignRole('Admin'); $this->token = $this->admin->createToken('test', ['staff'])->plainTextToken;
        $city = City::where('code', '1380200000')->firstOrFail(); $barangay = Barangay::where('code', '1380200001')->firstOrFail();
        $this->address = ['line1' => 'Demo logistics address', 'line2' => null, 'region_id' => $city->region_id, 'province_id' => null, 'city_id' => $city->id, 'barangay_id' => $barangay->id, 'postal_code' => '1740', 'contact_name' => 'Demo recipient', 'contact_phone' => '09170000000'];
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-logistics-test', 'name' => 'Demo customer']);
        $this->seller = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-logistics-test', 'shop_name' => 'Demo logistics seller']);
        $this->order = Order::create(['source_system' => 'orders', 'external_id' => 'order-logistics-test', 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $this->seller->id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $address = app(LogisticsService::class)->address($this->address);
        $this->warehouse = Warehouse::create(['name' => 'Demo test hub', 'code' => 'demo-test-hub', 'type' => 'platform_hub', 'address_id' => $address->id, 'is_active' => true]);
    }

    private function staff(): self { $this->app['auth']->forgetGuards(); return $this->withToken($this->token); }
    private function payload(array $replace = []): array { return array_replace(['order_id' => $this->order->id, 'seller_id' => $this->seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $this->warehouse->id, 'destination' => $this->address, 'weight_grams' => 1000, 'dimensions' => ['length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 0, 'tracking_number' => 'DEMO-LOGISTICS-001'], $replace); }
    private function shipment(array $replace = []): Shipment { $id = $this->staff()->postJson('/api/v1/logistics/shipments', $this->payload($replace))->assertCreated()->json('id'); return Shipment::findOrFail($id); }
    private function advance(Shipment $shipment): void { foreach (['ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery'] as $status) { $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => $status])->assertOk(); } }

    public function test_quotes_use_ceiling_weight_tiers_cod_and_active_rate_limits(): void
    {
        $city = $this->address['city_id'];
        $quote = $this->staff()->postJson('/api/v1/logistics/quotes', ['origin_city_id' => $city, 'destination_code' => '1380200000', 'weight_grams' => 1501, 'dimensions' => ['length_cm' => '35.01', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 10001])->assertOk()->json('data.0');
        $this->assertSame(1001, $quote['volumetric_weight_grams']); $this->assertSame(1501, $quote['chargeable_weight_grams']); $this->assertSame(3000, $quote['additional_fee_amount']); $this->assertSame(100, $quote['cod_fee_amount']); $this->assertSame(11100, $quote['total_amount']);
        $this->staff()->postJson('/api/v1/logistics/quotes', ['origin_code' => '1380200000', 'destination_city_id' => $city, 'weight_grams' => 20001, 'dimensions' => ['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10], 'cod_amount' => 0])->assertJsonCount(0, 'data');
    }

    public function test_shipment_crud_csv_parent_integrity_and_transition_attempt_limit(): void
    {
        $shipment = $this->shipment();
        $this->assertMatchesRegularExpression('/^SHP-\d{4}-\d{6}$/', $shipment->reference);
        $this->staff()->postJson('/api/v1/logistics/shipments', $this->payload())->assertUnprocessable();
        $this->staff()->getJson('/api/v1/logistics/options')->assertOk()->assertJsonCount(1, 'orders');
        $this->staff()->getJson('/api/v1/logistics/shipments?search='.$shipment->reference)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.to_ship', 1);
        $this->staff()->get('/api/v1/logistics/shipments?export=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->staff()->getJson('/api/v1/logistics/shipments/'.$shipment->id)->assertOk()->assertJsonCount(1, 'events');
        $this->staff()->putJson('/api/v1/logistics/shipments/'.$shipment->id, $this->payload(['weight_grams' => 1501]))->assertOk()->assertJsonPath('shipping_fee_amount', 11000);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'delivered'])->assertUnprocessable();
        $this->advance($shipment);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'failed_attempt', 'note' => 'Demo delivery failed'])->assertOk()->assertJsonPath('attempt_count', $attempt)->assertJsonPath('status', $attempt === 3 ? 'returning' : 'failed_attempt');
            if ($attempt < 3) { $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'out_for_delivery'])->assertOk(); }
        }
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'out_for_delivery'])->assertUnprocessable();
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'returned_to_sender'])->assertOk();
        $this->staff()->putJson('/api/v1/logistics/shipments/'.$shipment->id, $this->payload())->assertUnprocessable();
    }

    public function test_proof_requires_photo_recipient_and_cod_cash_and_captures_balanced_payment(): void
    {
        $this->mock(CommissionService::class, fn ($mock) => $mock->shouldReceive('capture')->once());
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-LOGISTICS-TEST']);
        $shipment = $this->shipment(['cod_amount' => 10000]); $this->advance($shipment);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'delivered'])->assertUnprocessable();
        $this->staff()->post('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery', ['recipient_name' => 'Demo recipient', 'photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=')), 'cash_collected' => false], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->staff()->post('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery', ['recipient_name' => 'Demo recipient', 'photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=')), 'cash_collected' => true], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'delivered')->assertJsonPath('cash_collected', true);
        $this->assertSame('captured', $payment->fresh()->status); $this->assertSame(10000, $payment->fresh()->captured_amount);
        $this->assertSame((int) DB::table('ledger_entries')->where('direction', 'debit')->sum('total_amount'), (int) DB::table('ledger_entries')->where('direction', 'credit')->sum('total_amount'));
        $this->staff()->get('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery/download')->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_cod_risk_review_blocks_shipping_and_holds_cash_until_cleared(): void
    {
        CommissionRule::create(['name' => 'Test global rule', 'scope' => 'global', 'rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subDay()]);
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-COD-HOLD', 'risk_level' => 'review']);
        $this->staff()->postJson('/api/v1/logistics/shipments', $this->payload(['cod_amount' => 10000]))->assertUnprocessable()->assertJsonValidationErrors('cod_amount');
        $payment->update(['risk_level' => 'none']); $shipment = $this->shipment(['cod_amount' => 10000]); $this->advance($shipment);
        $payment->update(['risk_level' => 'review']);
        $photo = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII='));
        $this->staff()->post('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery', ['recipient_name' => 'Demo recipient', 'photo' => $photo, 'cash_collected' => true], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'delivered');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('type', 'cod_risk_hold')->count());
        $this->staff()->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear')->assertOk()->assertJsonPath('status', 'captured')->assertJsonPath('captured_amount', 10000);
        $this->assertSame((int) DB::table('ledger_entries')->where('direction', 'debit')->sum('total_amount'), (int) DB::table('ledger_entries')->where('direction', 'credit')->sum('total_amount'));
        $this->assertTrue(DB::table('ledger_entries')->where('payment_id', $payment->id)->where('account', 'cash_on_delivery')->exists());
    }

    public function test_cod_amount_mismatch_alerts_finance_once_instead_of_capturing(): void
    {
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-COD-SHORT']);
        $shipment = $this->shipment(['cod_amount' => 6000]); $this->advance($shipment);
        $photo = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII='));
        $this->staff()->post('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery', ['recipient_name' => 'Demo recipient', 'photo' => $photo, 'cash_collected' => true], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'delivered');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->staff()->postJson('/api/v1/payments/'.$payment->id.'/cod-collected', ['cash_collected' => true])->assertUnprocessable()->assertJsonValidationErrors('cash_collected');
        $payment->update(['risk_level' => 'review']); $this->staff()->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear')->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('type', 'cod_amount_mismatch')->count());
    }

    public function test_configuration_crud_and_psgc_idempotent_import(): void
    {
        $city = $this->address['city_id'];
        $courierPayload = ['name' => 'Demo second courier', 'code' => 'demo-second', 'service_types' => ['standard'], 'is_active' => true, 'volumetric_divisor' => 3500, 'tracking_url_template' => null];
        $courier = $this->staff()->postJson('/api/v1/logistics/couriers', $courierPayload)->assertCreated()->assertJsonStructure(['webhook_secret'])->json('id');
        $this->staff()->getJson('/api/v1/logistics/couriers')->assertOk()->assertJsonMissingPath('data.0.webhook_secret');
        $this->staff()->putJson('/api/v1/logistics/couriers/'.$courier, array_replace($courierPayload, ['name' => 'Demo edited courier']))->assertOk();
        $zonePayload = ['name' => 'Demo test zone', 'province_ids' => [], 'city_ids' => [$city], 'min_days' => 0, 'max_days' => 2, 'is_active' => true];
        $zone = $this->staff()->postJson('/api/v1/logistics/zones', $zonePayload)->assertCreated()->json('id');
        $this->staff()->putJson('/api/v1/logistics/zones/'.$zone, $zonePayload)->assertOk();
        $ratePayload = ['courier_id' => $courier, 'service_type' => 'standard', 'origin_zone_id' => $zone, 'destination_zone_id' => $zone, 'base_weight_grams' => 500, 'base_fee_amount' => 1000, 'additional_per_500g_amount' => 500, 'cod_fee_bp' => 100, 'max_weight_grams' => 10000, 'is_active' => true];
        $rate = $this->staff()->postJson('/api/v1/logistics/rates', $ratePayload)->assertCreated()->json('id'); $this->staff()->putJson('/api/v1/logistics/rates/'.$rate, $ratePayload)->assertOk();
        $hubPayload = ['name' => 'Demo second hub', 'code' => 'demo-second-hub', 'type' => 'platform_hub', 'address' => $this->address, 'is_active' => true];
        $hub = $this->staff()->postJson('/api/v1/logistics/warehouses', $hubPayload)->assertCreated()->json('id'); $this->staff()->putJson('/api/v1/logistics/warehouses/'.$hub, $hubPayload)->assertOk();
        foreach (['couriers', 'zones', 'rates', 'warehouses'] as $kind) { $this->staff()->getJson('/api/v1/logistics/'.$kind)->assertOk(); $this->staff()->get('/api/v1/logistics/'.$kind.'?export=csv')->assertOk(); }
        $this->staff()->deleteJson('/api/v1/logistics/couriers/'.$courier)->assertUnprocessable(); $this->staff()->deleteJson('/api/v1/logistics/rates/'.$rate)->assertOk(); $this->staff()->deleteJson('/api/v1/logistics/couriers/'.$courier)->assertOk(); $this->staff()->deleteJson('/api/v1/logistics/zones/'.$zone)->assertOk(); $this->staff()->deleteJson('/api/v1/logistics/warehouses/'.$hub)->assertOk();
        $csv = "code,name,level,region_code,province_code,city_code\n1300000000,National Capital Region (NCR),Reg,,,\n1380200000,City of Las Piñas,City,1300000000,,\n1380200001,Almanza Uno,Bgy,1300000000,,1380200000\n";
        for ($round = 0; $round < 2; $round++) { $this->staff()->post('/api/v1/locations/import', ['file' => UploadedFile::fake()->createWithContent('PSGC.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.barangays', 1); }
        $this->assertSame(1, Barangay::where('code', '1380200001')->count());
        foreach (['regions', 'provinces', 'cities', 'barangays'] as $kind) { $this->staff()->getJson('/api/v1/locations/'.$kind)->assertOk(); $this->staff()->get('/api/v1/locations/'.$kind.'?export=csv')->assertOk(); }
        $bad = array_replace($this->address, ['city_id' => City::where('code', '1380300000')->value('id')]); $this->staff()->postJson('/api/v1/logistics/warehouses', array_replace($hubPayload, ['code' => 'bad-parent', 'address' => $bad]))->assertUnprocessable();
    }

    public function test_courier_signature_deduplicates_and_rejects_expired_events(): void
    {
        $shipment = $this->shipment(); $courier = $shipment->courier; $timestamp = now()->timestamp; $body = json_encode(['id' => 'demo-courier-event-1', 'tracking_number' => $shipment->tracking_number, 'status' => 'ready_for_pickup']);
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $courier->webhook_secret); $path = '/api/v1/webhooks/couriers/'.$courier->code;
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_SIGNATURE' => $signature], $body)->assertOk();
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_SIGNATURE' => $signature], $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, $shipment->events()->count());
        $expired = $timestamp - 301; $signature = 't='.$expired.',v1='.hash_hmac('sha256', $expired.'.'.$body, $courier->webhook_secret);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_SIGNATURE' => $signature], $body)->assertUnauthorized();
    }

    public function test_integration_scope_idempotency_and_source_isolation(): void
    {
        $client = IntegrationClient::create(['name' => 'Orders test', 'system_code' => 'orders', 'is_active' => true]); $token = $client->createToken('logistics', ['shipments:write', 'shipments:read', 'quotes:read'])->plainTextToken;
        $this->app['auth']->forgetGuards(); $payload = $this->payload(); $response = $this->withToken($token)->withHeader('Idempotency-Key', 'shipment-demo-test')->postJson('/api/v1/integrations/shipments', $payload)->assertCreated();
        $this->app['auth']->forgetGuards(); $this->withToken($token)->postJson('/api/v1/integrations/shipments', $payload)->assertCreated()->assertJsonPath('id', $response->json('id'));
        $this->app['auth']->forgetGuards(); $this->withToken($token)->getJson('/api/v1/integrations/shipments/'.$response->json('reference'))->assertOk();
        $other = IntegrationClient::create(['name' => 'Other test', 'system_code' => 'other', 'is_active' => true]); $otherToken = $other->createToken('read', ['shipments:read'])->plainTextToken;
        $this->app['auth']->forgetGuards(); $this->withToken($otherToken)->getJson('/api/v1/integrations/shipments/'.$response->json('reference'))->assertNotFound();
    }

    public function test_create_without_courier_uses_the_recommended_quote_and_an_explicit_courier_is_honoured(): void
    {
        $quotes = $this->staff()->postJson('/api/v1/logistics/quotes', ['origin_city_id' => $this->address['city_id'], 'destination_city_id' => $this->address['city_id'], 'weight_grams' => 1000, 'dimensions' => ['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10], 'cod_amount' => 0])->assertOk()->json('data');
        $this->assertSame([true, false], array_column($quotes, 'recommended')); $this->assertSame('standard', $quotes[0]['service_type']);
        $noCourier = array_diff_key($this->payload(), array_flip(['courier_id', 'service_type']));
        $this->staff()->postJson('/api/v1/logistics/shipments', array_replace($noCourier, ['courier_id' => $quotes[0]['courier_id']]))->assertUnprocessable()->assertJsonValidationErrors('service_type');
        $this->staff()->postJson('/api/v1/logistics/shipments', array_replace($noCourier, ['weight_grams' => 20001]))->assertUnprocessable()->assertJsonValidationErrors('courier_id');
        $this->staff()->postJson('/api/v1/logistics/shipments', $noCourier)->assertCreated()->assertJsonPath('service_type', 'standard')->assertJsonPath('courier_id', $quotes[0]['courier_id'])->assertJsonPath('shipping_fee_amount', 8000);
        $express = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-express-test', 'shop_name' => 'Express seller']);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $express->id, 'product_name' => 'Express item', 'quantity' => 1, 'unit_price_amount' => 100, 'line_total_amount' => 100]);
        $id = $this->staff()->postJson('/api/v1/logistics/shipments', $this->payload(['seller_id' => $express->id, 'service_type' => 'express', 'tracking_number' => 'DEMO-EXPRESS-001']))->assertCreated()->assertJsonPath('service_type', 'express')->assertJsonPath('shipping_fee_amount', 12000)->json('id');
        // A tracking-only edit keeps the courier and service without choosing a rate again.
        $this->staff()->putJson('/api/v1/logistics/shipments/'.$id, array_replace(array_diff_key($this->payload(['seller_id' => $express->id]), array_flip(['courier_id', 'service_type'])), ['tracking_number' => 'DEMO-EDITED']))->assertOk()->assertJsonPath('service_type', 'express')->assertJsonPath('tracking_number', 'DEMO-EDITED')->assertJsonPath('shipping_fee_amount', 12000);
        // Integration create without a courier stays inside the client's source system.
        $third = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-integration-test', 'shop_name' => 'Integration seller']);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $third->id, 'product_name' => 'Integration item', 'quantity' => 1, 'unit_price_amount' => 100, 'line_total_amount' => 100]);
        $integration = array_replace(array_diff_key($noCourier, array_flip(['order_id', 'seller_id'])), ['order_external_id' => $this->order->external_id, 'seller_external_id' => $third->external_id, 'tracking_number' => 'DEMO-INTEGRATION-001']);
        $other = IntegrationClient::create(['name' => 'Other shipping test', 'system_code' => 'other', 'is_active' => true]);
        $this->app['auth']->forgetGuards(); $this->withToken($other->createToken('ship', ['shipments:write'])->plainTextToken)->withHeader('Idempotency-Key', 'shipment-other-test')->postJson('/api/v1/integrations/shipments', $integration)->assertNotFound();
        $client = IntegrationClient::create(['name' => 'Orders shipping test', 'system_code' => 'orders', 'is_active' => true]);
        $this->app['auth']->forgetGuards(); $this->withToken($client->createToken('ship', ['shipments:write'])->plainTextToken)->withHeader('Idempotency-Key', 'shipment-orders-test')->postJson('/api/v1/integrations/shipments', $integration)->assertCreated()->assertJsonPath('service_type', 'standard')->assertJsonPath('seller_id', $third->id);
    }

    public function test_awaiting_booking_lists_paid_unshipped_pairs_with_suggestions(): void
    {
        $this->getJson('/api/v1/logistics/awaiting-booking')->assertUnauthorized();
        foreach (['Support Agent', 'Finance'] as $role) {
            $user = User::create(['name' => $role.' test', 'email' => str_replace(' ', '.', strtolower($role)).'.awaiting@example.com', 'password' => 'password', 'is_active' => true]); $user->assignRole($role);
            $this->app['auth']->forgetGuards(); $this->withToken($user->createToken('test', ['staff'])->plainTextToken)->getJson('/api/v1/logistics/awaiting-booking')->assertForbidden();
        }
        $this->staff()->getJson('/api/v1/logistics/awaiting-booking?sort=bogus')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $split = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-awaiting-split', 'shop_name' => 'Second awaiting seller']);
        $pickup = Warehouse::create(['name' => 'Split seller pickup', 'code' => 'split-pickup', 'type' => 'seller_pickup', 'seller_id' => $split->id, 'address_id' => app(LogisticsService::class)->address($this->address)->id, 'is_active' => true]);
        $order = function (string $key, array $sellers, ?array $payment, string $status = 'placed') {
            $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-awaiting-'.$key, 'customer_id' => $this->order->customer_id, 'status_snapshot' => $status, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
            foreach ($sellers as $seller) { OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Awaiting item', 'quantity' => 1, 'unit_price_amount' => 5000, 'line_total_amount' => 5000]); }
            if ($payment) { Payment::create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'method' => $payment[0], 'gateway' => $payment[0] === 'cod' ? 'cod' : 'sandbox', 'status' => $payment[1], 'risk_level' => $payment[2] ?? 'none', 'total_amount' => 10000, 'captured_amount' => $payment[1] === 'pending' ? 0 : 10000, 'reference' => 'PAY-AWAITING-'.$key]); }
            return $order;
        };
        Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'card', 'gateway' => 'sandbox', 'status' => 'captured', 'total_amount' => 10000, 'captured_amount' => 10000, 'reference' => 'PAY-AWAITING-PREPAID']);
        $cod = $order('cod', [$this->seller], ['cod', 'pending']); $splitOrder = $order('split', [$this->seller, $split], ['cod', 'pending']);
        $order('held', [$this->seller], ['cod', 'pending', 'review']); $order('cancelled', [$this->seller], ['card', 'captured'], 'cancelled'); $order('refunded', [$this->seller], ['card', 'refunded']); $order('unpaid', [$this->seller], ['card', 'pending']); $order('no-payment', [$this->seller], null);
        $hub = Warehouse::where('type', 'platform_hub')->where('is_active', true)->orderBy('id')->value('id');
        $rows = collect($this->staff()->getJson('/api/v1/logistics/awaiting-booking?per_page=100')->assertOk()->assertJsonPath('total', 4)->assertJsonPath('summary.awaiting_booking', 4)
            ->assertJsonStructure(['current_page', 'data' => [['id', 'external_id', 'seller_id', 'seller_name', 'customer', 'suggested' => ['origin_warehouse_id', 'cod_amount', 'destination']]], 'last_page', 'per_page', 'total', 'summary'])->json('data'));
        $find = fn ($rows, Order $order, Seller $seller) => $rows->first(fn ($row) => $row['id'] === $order->id && (int) $row['seller_id'] === $seller->id);
        $this->assertSame(['origin_warehouse_id' => $hub, 'cod_amount' => 0, 'destination' => null], $find($rows, $this->order, $this->seller)['suggested']);
        $this->assertSame(10000, $find($rows, $cod, $this->seller)['suggested']['cod_amount']);
        $this->assertNull($find($rows, $splitOrder, $this->seller)['suggested']['cod_amount']);
        $this->assertSame($pickup->id, $find($rows, $splitOrder, $split)['suggested']['origin_warehouse_id']);
        $this->staff()->getJson('/api/v1/logistics/awaiting-booking?search=second+awaiting')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.seller_name', 'Second awaiting seller');
        $this->staff()->get('/api/v1/logistics/awaiting-booking?export=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        // Booking a pair with only weight and dimensions removes it; the customer's last address is then suggested.
        $this->staff()->postJson('/api/v1/logistics/shipments', array_diff_key($this->payload(), array_flip(['courier_id', 'service_type'])))->assertCreated();
        $rows = collect($this->staff()->getJson('/api/v1/logistics/awaiting-booking?per_page=100')->assertOk()->assertJsonPath('total', 3)->json('data'));
        $this->assertNull($find($rows, $this->order, $this->seller));
        $this->assertSame('Demo logistics address', $find($rows, $cod, $this->seller)['suggested']['destination']['line1']);
        $this->assertContains(['key' => 'awaiting_booking', 'count' => 3], $this->staff()->getJson('/api/v1/dashboard')->assertOk()->json('attention'));
    }

    public function test_overdue_and_stuck_shipments_are_flagged_once_in_the_business_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:30:00', 'UTC')); // 23:30 on the 14th in Manila
        $late = $this->shipment(); $late->update(['promised_to' => '2026-10-14']);
        $done = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-delivered-test', 'shop_name' => 'Delivered seller']);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $done->id, 'product_name' => 'Delivered item', 'quantity' => 1, 'unit_price_amount' => 100, 'line_total_amount' => 100]);
        $this->shipment(['seller_id' => $done->id, 'tracking_number' => 'DEMO-DELIVERED-001'])->update(['promised_to' => '2026-10-01', 'status' => 'delivered']);
        $service = app(LogisticsService::class);
        $this->staff()->getJson('/api/v1/logistics/shipments?overdue=1')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.overdue', 0);
        $this->assertSame(['shipments_overdue_flagged' => 0, 'shipments_stuck_flagged' => 0], $service->runScheduled());

        $this->travelTo(CarbonImmutable::parse('2026-10-14 16:30:00', 'UTC')); // 00:30 on the 15th in Manila, still the 14th in UTC
        $this->staff()->getJson('/api/v1/logistics/shipments?overdue=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $late->id)->assertJsonPath('summary.overdue', 1);
        $this->staff()->getJson('/api/v1/logistics/shipments?overdue=maybe')->assertUnprocessable()->assertJsonValidationErrors('overdue');
        $this->assertSame(['shipments_overdue_flagged' => 1, 'shipments_stuck_flagged' => 0], $service->runScheduled());
        $this->assertSame(0, $service->runScheduled()['shipments_overdue_flagged'], 'Each overdue shipment is flagged once.');
        $this->assertContains(['key' => 'overdue_shipments', 'count' => 1], $this->staff()->getJson('/api/v1/dashboard')->assertOk()->json('attention'));

        // Stuck after 48 hours without an event, and again only after a newer event. The delivered shipment never alerts.
        $this->travel(48)->hours();
        $this->assertSame(['shipments_overdue_flagged' => 0, 'shipments_stuck_flagged' => 1], $service->runScheduled());
        $this->assertSame(0, $service->runScheduled()['shipments_stuck_flagged']);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$late->id.'/events', ['status' => 'ready_for_pickup'])->assertOk();
        $this->travel(47)->hours(); $this->assertSame(0, $service->runScheduled()['shipments_stuck_flagged']);
        $this->travel(2)->hours(); $this->assertSame(1, $service->runScheduled()['shipments_stuck_flagged']);
        $digests = Notification::where('user_id', $this->admin->id)->where('type', 'shipments_attention')->orderBy('id')->get();
        $this->assertCount(3, $digests); $this->assertStringContainsString($late->reference, $digests->first()->message);

        // A Support Agent sees only shipments linked to their tickets and has no overdue row on the dashboard.
        $agent = User::create(['name' => 'Agent overdue test', 'email' => 'agent.overdue@example.com', 'password' => 'password', 'is_active' => true]); $agent->assignRole('Support Agent');
        $token = $agent->createToken('test', ['staff'])->plainTextToken;
        $this->app['auth']->forgetGuards(); $this->withToken($token)->getJson('/api/v1/logistics/shipments?overdue=1')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.overdue', 0);
        $this->app['auth']->forgetGuards(); $this->assertNotContains('overdue_shipments', array_column($this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->json('attention'), 'key'));
    }

    public function test_failed_attempts_open_one_delivery_ticket_that_later_attempts_update(): void
    {
        $shipment = $this->shipment(); $this->advance($shipment);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'failed_attempt', 'note' => 'Nobody home'])->assertOk();
        $ticket = Ticket::where('shipment_id', $shipment->id)->sole();
        $this->assertSame(['system', 'delivery', 'normal', ['auto', 'delivery'], 'customer', $this->order->customer_id, $this->order->id], [$ticket->channel, $ticket->category, $ticket->priority, $ticket->tags, $ticket->requester_type, $ticket->requester_id, $ticket->order_id]);
        $this->assertSame(['system', 'Delivery attempt 1 of 3 for '.$shipment->reference.' failed: Nobody home'], [$ticket->messages->first()->author_type, $ticket->messages->first()->body]);
        // A second call for the same attempt (another caller waiting on the shipment lock) adds nothing.
        app(LogisticsService::class)->deliveryTicket($shipment->id);
        $this->assertSame(1, Ticket::where('shipment_id', $shipment->id)->count()); $this->assertSame(1, $ticket->messages()->count());
        $this->staff()->getJson('/api/v1/logistics/shipments/'.$shipment->id)->assertOk()->assertJsonPath('related_ticket.id', $ticket->id)->assertJsonPath('related_ticket.reference', $ticket->reference);

        // Courier events update the same ticket; the third failure raises it to high priority.
        $courier = $shipment->courier; $path = '/api/v1/webhooks/couriers/'.$courier->code;
        $send = function (string $id, string $status) use ($shipment, $courier, $path) {
            $timestamp = now()->timestamp; $body = json_encode(['id' => $id, 'tracking_number' => $shipment->tracking_number, 'status' => $status]);
            $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $courier->webhook_secret)], $body)->assertOk();
        };
        foreach ([2, 3] as $attempt) { $send('redispatch-'.$attempt, 'out_for_delivery'); $send('failed-'.$attempt, 'failed_attempt'); }
        $ticket->refresh();
        $this->assertSame(1, Ticket::where('shipment_id', $shipment->id)->count());
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(2, $ticket->messages()->where('author_type', 'system')->where('is_internal_note', true)->count());
        $this->assertTrue($ticket->messages()->where('body', 'Delivery attempt 3 of 3 for '.$shipment->reference.' failed. The parcel is returning to the sender.')->exists());
        $this->assertTrue(AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->where('new_values->automation', 'logistics_auto_ticket')->where('new_values->priority', 'high')->exists());
    }

    public function test_a_ticket_error_never_undoes_the_shipment_update_a_delivery_is_noted_and_the_rule_can_be_switched_off(): void
    {
        $shipment = $this->shipment(); $this->advance($shipment); $events = '/api/v1/logistics/shipments/'.$shipment->id.'/events';
        $this->mock(TicketService::class, fn ($mock) => $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('Ticket store unavailable.')));
        $this->staff()->postJson($events, ['status' => 'failed_attempt'])->assertOk()->assertJsonPath('status', 'failed_attempt')->assertJsonPath('attempt_count', 1);
        $this->assertSame(0, Ticket::count());
        $this->app->instance(TicketService::class, new TicketService);
        $this->staff()->postJson($events, ['status' => 'out_for_delivery'])->assertOk(); $this->staff()->postJson($events, ['status' => 'failed_attempt'])->assertOk();
        $ticket = Ticket::where('shipment_id', $shipment->id)->sole();
        $this->staff()->postJson($events, ['status' => 'out_for_delivery'])->assertOk();
        $photo = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII='));
        $this->staff()->post('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery', ['recipient_name' => 'Demo recipient', 'photo' => $photo], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'delivered');
        $this->assertTrue($ticket->messages()->where('is_internal_note', true)->where('body', $shipment->reference.' was delivered to Demo recipient.')->exists());

        Setting::updateOrCreate(['key' => 'logistics_auto_ticket'], ['value' => false]);
        $other = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-ticket-off-test', 'shop_name' => 'Ticket off seller']);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $other->id, 'product_name' => 'Ticket off item', 'quantity' => 1, 'unit_price_amount' => 100, 'line_total_amount' => 100]);
        $second = $this->shipment(['seller_id' => $other->id, 'tracking_number' => 'DEMO-TICKET-OFF']); $this->advance($second);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$second->id.'/events', ['status' => 'failed_attempt'])->assertOk();
        $this->assertSame(1, Ticket::count());
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role.' logistics test', 'email' => str_replace(' ', '.', strtolower($role)).'.logistics@example.com', 'password' => 'password', 'is_active' => true]); $user->assignRole($role);
        return $user;
    }

    private function courierEvent(Shipment $shipment, string $id, string $status, array $extra = [])
    {
        $courier = $shipment->courier; $timestamp = now()->timestamp; $body = json_encode(['id' => $id, 'tracking_number' => $shipment->tracking_number, 'status' => $status] + $extra);
        return $this->call('POST', '/api/v1/webhooks/couriers/'.$courier->code, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $courier->webhook_secret)], $body);
    }

    public function test_a_returned_cod_parcel_cancels_its_payment_a_prepaid_one_alerts_finance_and_pickup_needs_tracking(): void
    {
        [$agent, $finance, $coordinator] = [$this->user('Support Agent'), $this->user('Finance'), $this->user('Logistics Coordinator')];
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-COD-RETURN']);
        $shipment = $this->shipment(['cod_amount' => 10000]); $this->advance($shipment); $events = '/api/v1/logistics/shipments/'.$shipment->id.'/events';
        foreach ([1, 2, 3] as $attempt) { $this->staff()->postJson($events, ['status' => 'failed_attempt'])->assertOk(); if ($attempt < 3) { $this->staff()->postJson($events, ['status' => 'out_for_delivery'])->assertOk(); } }
        $this->assertSame('pending', $payment->fresh()->status, 'A returning parcel keeps the payment until it is back.');
        $this->staff()->postJson($events, ['status' => 'returned_to_sender'])->assertOk();
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertTrue(AuditLog::where('entity_type', 'Payment')->where('entity_id', (string) $payment->id)->where('new_values->automation', 'logistics_return_cod_cancel')->exists());
        // Shipment bells go to people who edit shipments only.
        foreach (['shipment_failed_attempt', 'shipment_returned_to_sender'] as $type) {
            $this->assertTrue(Notification::where('user_id', $coordinator->id)->where('type', $type)->exists());
            $this->assertFalse(Notification::whereIn('user_id', [$agent->id, $finance->id])->where('type', $type)->exists());
        }

        // A prepaid order: pickup needs a tracking number, and a cancelled parcel is flagged to Finance.
        $prepaid = Order::create(['source_system' => 'orders', 'external_id' => 'order-prepaid-return', 'customer_id' => $this->order->customer_id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $prepaid->id, 'seller_id' => $this->seller->id, 'product_name' => 'Prepaid item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $card = Payment::create(['order_id' => $prepaid->id, 'customer_id' => $prepaid->customer_id, 'method' => 'card', 'gateway' => 'sandbox', 'status' => 'captured', 'total_amount' => 10000, 'captured_amount' => 10000, 'reference' => 'PAY-2026-PREPAID-RETURN']);
        $untracked = $this->shipment(['order_id' => $prepaid->id, 'tracking_number' => null]); $events = '/api/v1/logistics/shipments/'.$untracked->id.'/events';
        $this->staff()->postJson($events, ['status' => 'ready_for_pickup'])->assertOk();
        $this->staff()->postJson($events, ['status' => 'picked_up'])->assertUnprocessable()->assertJsonValidationErrors('tracking_number');
        $this->staff()->postJson($events, ['status' => 'cancelled'])->assertOk();
        $alert = Notification::where('user_id', $finance->id)->where('type', 'parcel_returned')->sole();
        $this->assertSame([$card->id, $untracked->id], [$alert->data['payment_id'], $alert->data['shipment_id']]);
        $this->assertFalse(Notification::whereIn('user_id', [$agent->id, $coordinator->id])->where('type', 'parcel_returned')->exists());
        $this->assertSame('captured', $card->fresh()->status);
    }

    public function test_order_cancel_cancels_shipments_not_yet_with_the_courier_and_refuses_otherwise(): void
    {
        $client = IntegrationClient::create(['name' => 'Orders cancel test', 'system_code' => 'orders', 'is_active' => true]); $token = $client->createToken('orders', ['orders:write'])->plainTextToken;
        WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/hook', 'events' => ['shipment.status_changed'], 'secret' => 'secret', 'is_active' => true]);
        $integration = function () use ($token) { $this->app['auth']->forgetGuards(); return $this->withToken($token); };
        $this->postJson('/api/v1/integrations/orders/'.$this->order->external_id.'/cancel')->assertUnauthorized();
        $this->withToken($client->createToken('read', ['payments:read'])->plainTextToken)->postJson('/api/v1/integrations/orders/'.$this->order->external_id.'/cancel')->assertForbidden();
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-COD-CANCEL']);
        $pending = $this->shipment(['cod_amount' => 10000]);
        $integration()->postJson('/api/v1/integrations/orders/'.$this->order->external_id.'/cancel')->assertOk()->assertJsonPath('status_snapshot', 'cancelled');
        $this->assertSame(['cancelled', 'cancelled', 'integration'], [$pending->fresh()->status, $payment->fresh()->status, $pending->events()->reorder()->latest('id')->value('source')]);
        $this->assertTrue(DB::table('webhook_deliveries')->where('event_type', 'shipment.status_changed')->exists());

        // A shipment already with the courier refuses the cancel and changes nothing, on every cancel path (the Integration
        // Simulator cancels through the same integration route).
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-cancel-transit', 'customer_id' => $this->order->customer_id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $this->seller->id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $transit = $this->shipment(['order_id' => $order->id, 'tracking_number' => 'DEMO-TRANSIT-001']);
        foreach (['ready_for_pickup', 'picked_up', 'in_transit'] as $status) { $this->staff()->postJson('/api/v1/logistics/shipments/'.$transit->id.'/events', ['status' => $status])->assertOk(); }
        $integration()->postJson('/api/v1/integrations/orders/'.$order->external_id.'/cancel')->assertUnprocessable()->assertJsonValidationErrors('order');
        $upsert = ['customer_external_id' => 'customer-logistics-test', 'status_snapshot' => 'cancelled', 'subtotal_amount' => 10000, 'shipping_amount' => 0, 'discount_amount' => 0, 'total_amount' => 10000, 'placed_at' => now()->toIso8601String(), 'items' => [['seller_external_id' => $this->seller->external_id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]]];
        $integration()->putJson('/api/v1/integrations/orders/'.$order->external_id, $upsert)->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(['placed', 'in_transit'], [$order->fresh()->status_snapshot, $transit->fresh()->status]);
    }

    public function test_courier_gap_fill_is_off_by_default_and_each_refused_event_alerts_shipment_editors_once(): void
    {
        $agent = $this->user('Support Agent'); $shipment = $this->shipment();
        $this->courierEvent($shipment, 'gap-1', 'picked_up')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->courierEvent($shipment, 'gap-1', 'picked_up')->assertUnprocessable();
        $alert = Notification::where('user_id', $this->admin->id)->where('type', 'courier_event_rejected')->sole();
        $this->assertStringContainsString($shipment->reference, $alert->message); $this->assertSame($shipment->id, $alert->data['shipment_id']);
        $this->assertFalse(Notification::where('user_id', $agent->id)->where('type', 'courier_event_rejected')->exists());
        $this->assertSame('pending', $shipment->fresh()->status);

        $this->staff()->putJson('/api/v1/settings', ['logistics_courier_fill_gaps' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('logistics_courier_fill_gaps');
        $this->staff()->putJson('/api/v1/settings', ['logistics_courier_fill_gaps' => true])->assertOk();
        Cache::forever('sfa.api.version', 'before-courier-event');
        $this->courierEvent($shipment, 'gap-2', 'picked_up')->assertOk()->assertJsonPath('shipment.status', 'picked_up');
        $this->assertNotSame('before-courier-event', Cache::get('sfa.api.version'));
        $this->assertSame(['pending', 'ready_for_pickup', 'picked_up'], $shipment->events()->pluck('status')->all());
        $inferred = $shipment->events()->where('status', 'ready_for_pickup')->sole(); $this->assertSame(['Inferred from a later courier event.', 'courier_webhook'], [$inferred->note, $inferred->source]);
        // Delivered is never inferred: the event is refused, nothing is recorded, and staff are told.
        $this->courierEvent($shipment, 'gap-3', 'delivered')->assertUnprocessable();
        $this->assertSame(3, $shipment->events()->count());
        $this->assertSame(2, Notification::where('user_id', $this->admin->id)->where('type', 'courier_event_rejected')->count());
    }

    public function test_tracking_only_edits_preserve_the_booking_when_rates_are_no_longer_available(): void
    {
        $shipment = $this->shipment();
        $original = $shipment->only(['shipping_fee_amount', 'promised_from', 'promised_to', 'destination_address_id']);
        Rate::query()->update(['is_active' => false]); $this->travel(2)->days();
        $payload = $this->payload(['tracking_number' => 'TRACKING-UPDATED']); unset($payload['courier_id'], $payload['service_type']);
        $this->staff()->putJson('/api/v1/logistics/shipments/'.$shipment->id, $payload)->assertOk()->assertJsonPath('tracking_number', 'TRACKING-UPDATED');
        $this->assertEquals($original, $shipment->fresh()->only(array_keys($original)));
        $this->staff()->putJson('/api/v1/logistics/shipments/'.$shipment->id, array_replace($payload, ['weight_grams' => 1500]))->assertUnprocessable()->assertJsonValidationErrors('courier_id');
    }

    public function test_delivery_escalation_uses_the_staff_priority_sla_rule_and_preserves_paused_time(): void
    {
        $shipment = $this->shipment(); $this->advance($shipment);
        $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'failed_attempt'])->assertOk();
        $ticket = Ticket::where('shipment_id', $shipment->id)->sole();
        $ticket->update(['resolution_paused_seconds' => 7200, 'sla_at_risk_notified_at' => now(), 'sla_breached_notified_at' => now()]);
        $staffTicket = app(TicketService::class)->create(['subject' => 'Same SLA rule', 'description' => 'Compare the two priority changes.', 'requester_type' => 'customer', 'requester_id' => $this->order->customer_id, 'order_id' => $this->order->id, 'channel' => 'email', 'category' => 'other', 'priority' => 'normal'], $this->admin);
        $staffTicket->update(['created_at' => $ticket->created_at, 'resolution_paused_seconds' => 7200, 'sla_at_risk_notified_at' => now(), 'sla_breached_notified_at' => now()]);
        $staffTicket = app(TicketService::class)->update($staffTicket, ['priority' => 'high'], $this->admin);
        foreach ([2, 3] as $attempt) {
            $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'out_for_delivery'])->assertOk();
            $this->staff()->postJson('/api/v1/logistics/shipments/'.$shipment->id.'/events', ['status' => 'failed_attempt'])->assertOk();
        }
        $ticket->refresh();
        $this->assertSame($staffTicket->first_response_due_at->timestamp, $ticket->first_response_due_at->timestamp);
        $this->assertSame($staffTicket->resolution_due_at->timestamp, $ticket->resolution_due_at->timestamp);
        $this->assertNull($ticket->sla_at_risk_notified_at); $this->assertNull($ticket->sla_breached_notified_at);
        $this->assertSame(7200, $ticket->resolution_paused_seconds);
    }

    public function test_order_cancellation_and_delivery_lock_the_order_then_payment_before_shipments(): void
    {
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-COD-LOCK-ORDER']);
        $shipment = $this->shipment(['cod_amount' => 10000]); $sequence = [];
        Order::retrieved(function (Order $row) use (&$sequence) { if (DB::transactionLevel() > 0) { $sequence[] = 'order'; } });
        Payment::retrieved(function (Payment $row) use (&$sequence) { if (DB::transactionLevel() > 0) { $sequence[] = 'payment'; } });
        Shipment::retrieved(function (Shipment $row) use (&$sequence) { if (DB::transactionLevel() > 0) { $sequence[] = 'shipment'; } });
        $service = app(LogisticsService::class);
        $service->transition($shipment, ['status' => 'ready_for_pickup']);
        $this->assertSame(['order', 'payment', 'shipment'], array_slice($sequence, 0, 3));
        $sequence = []; $service->cancelForOrder($this->order);
        $this->assertSame(['order', 'payment', 'shipment'], array_slice($sequence, 0, 3));
        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_a_shipping_quote_changes_nothing_so_it_writes_no_audit_entry(): void
    {
        $before = \App\Models\AuditLog::count();
        $this->staff()->postJson('/api/v1/logistics/quotes', ['origin_city_id' => $this->address['city_id'], 'destination_city_id' => $this->address['city_id'], 'weight_grams' => 1000, 'dimensions' => ['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10], 'cod_amount' => 0])->assertOk();
        $this->assertSame($before, \App\Models\AuditLog::count());
    }

    public function test_quotes_return_exactly_the_documented_shipping_quote_fields(): void
    {
        // Keep in step with the ShippingQuote schema in scripts/build-openapi.py.
        $quote = $this->staff()->postJson('/api/v1/logistics/quotes', ['origin_city_id' => $this->address['city_id'], 'destination_city_id' => $this->address['city_id'], 'weight_grams' => 1000, 'dimensions' => ['length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10], 'cod_amount' => 0])->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(['rate_id', 'courier_id', 'courier_name', 'service_type', 'actual_weight_grams', 'volumetric_weight_grams', 'chargeable_weight_grams', 'volumetric_divisor', 'base_fee_amount', 'additional_fee_amount', 'cod_fee_amount', 'total_amount', 'currency', 'eta_from', 'eta_to', 'recommended'], array_keys($quote));
    }

    public function test_a_partially_refunded_order_still_awaits_booking_and_a_fully_refunded_one_does_not(): void
    {
        foreach (['partially_refunded' => 4000, 'refunded' => 10000] as $status => $refunded) {
            $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-awaiting-'.$status, 'customer_id' => $this->order->customer_id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
            OrderItem::create(['order_id' => $order->id, 'seller_id' => $this->seller->id, 'product_name' => 'Refund item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
            Payment::create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'method' => 'card', 'gateway' => 'sandbox', 'status' => $status, 'total_amount' => 10000, 'captured_amount' => 10000, 'refunded_amount' => $refunded, 'reference' => 'PAY-AWAITING-'.strtoupper($status)]);
        }
        $this->staff()->getJson('/api/v1/logistics/awaiting-booking')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.external_id', 'order-awaiting-partially_refunded')->assertJsonPath('summary.awaiting_booking', 1);
        $this->assertContains(['key' => 'awaiting_booking', 'count' => 1], $this->staff()->getJson('/api/v1/dashboard')->assertOk()->json('attention'));
    }

    public function test_courier_proof_of_delivery_is_off_by_default_prepaid_only_and_checks_the_photo(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=';
        $proof = ['recipient_name' => 'Courier recipient', 'proof_photo' => $png, 'note' => 'Left with the guard.'];
        $shipment = $this->shipment();
        // Off by default: refused with nothing stored, and staff who edit shipments are told.
        $this->courierEvent($shipment, 'pod-off', 'delivered', $proof)->assertUnprocessable()->assertJsonValidationErrors('proof_photo');
        $this->assertStringContainsString('courier proof of delivery', Notification::where('user_id', $this->admin->id)->where('type', 'courier_event_rejected')->sole()->message);
        $this->staff()->putJson('/api/v1/settings', ['logistics_courier_pod' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('logistics_courier_pod');
        $coordinator = $this->user('Logistics Coordinator')->createToken('test', ['staff'])->plainTextToken; $this->app['auth']->forgetGuards();
        $this->withToken($coordinator)->putJson('/api/v1/settings', ['logistics_courier_pod' => true])->assertForbidden();
        $this->staff()->putJson('/api/v1/settings', ['logistics_courier_pod' => true])->assertOk()->assertJsonPath('logistics_courier_pod', true);
        // Only an out-for-delivery parcel takes proof, and the recipient and photo come together.
        $this->courierEvent($shipment, 'pod-pending', 'delivered', $proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->advance($shipment);
        $this->courierEvent($shipment, 'pod-pair', 'delivered', ['proof_photo' => $png])->assertUnprocessable()->assertJsonValidationErrors('recipient_name');
        $large = base64_encode(base64_decode($png).str_repeat("\0", 2 * 1024 * 1024));
        foreach (['bad' => 'not base64!', 'mime' => base64_encode('plain text, not an image'), 'large' => $large] as $case => $photo) {
            $this->courierEvent($shipment, 'pod-'.$case, 'delivered', ['proof_photo' => $photo] + $proof)->assertUnprocessable()->assertJsonValidationErrors('proof_photo');
        }
        $this->assertSame(['out_for_delivery', null], [$shipment->fresh()->status, $shipment->fresh()->proof_attachment_id]);
        $this->assertSame(0, Attachment::count());

        // A COD parcel always needs staff proof with cash collection.
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-pod-cod', 'customer_id' => $this->order->customer_id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $this->seller->id, 'product_name' => 'COD item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $payment = Payment::create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'method' => 'cod', 'gateway' => 'cod', 'total_amount' => 10000, 'reference' => 'PAY-2026-POD-COD']);
        $cod = $this->shipment(['order_id' => $order->id, 'cod_amount' => 10000, 'tracking_number' => 'DEMO-POD-COD']); $this->advance($cod);
        $this->courierEvent($cod, 'pod-cod', 'delivered', $proof)->assertUnprocessable()->assertJsonValidationErrors('proof_photo');
        $this->assertSame(['out_for_delivery', 'pending'], [$cod->fresh()->status, $payment->fresh()->status]);

        // A prepaid parcel out for delivery is delivered with the courier's proof, stored with no uploader.
        $this->courierEvent($shipment, 'pod-ok', 'delivered', $proof)->assertOk()->assertJsonPath('shipment.status', 'delivered')->assertJsonPath('shipment.recipient_name', 'Courier recipient')->assertJsonPath('shipment.cash_collected', false)->assertJsonPath('shipment.proof_attachment.mime_type', 'image/png')->assertJsonMissingPath('shipment.proof_attachment.content');
        $attachment = Attachment::sole();
        $this->assertSame([$attachment->id, 'Left with the guard.', null, Shipment::class], [$shipment->fresh()->proof_attachment_id, $shipment->fresh()->delivery_note, $attachment->uploaded_by, $attachment->attachable_type]);
        $this->assertSame('courier_webhook', $shipment->events()->reorder()->latest('id')->value('source'));
        $this->staff()->get('/api/v1/logistics/shipments/'.$shipment->id.'/proof-of-delivery/download')->assertOk()->assertHeader('Content-Type', 'image/png');
    }
}
