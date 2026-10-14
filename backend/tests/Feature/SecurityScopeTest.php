<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Seller;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\BillingService;
use App\Services\LogisticsService;
use App\Services\TicketService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{order: Order, payment: Payment, shipment: Shipment, ticket: ?Ticket}> */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class, LogisticsDemoSeeder::class]);
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'scope-customer', 'name' => 'Scope customer', 'email' => 'scope.customer@example.com']);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'scope-seller', 'shop_name' => 'Scope shop']);
        $city = City::where('code', '1380200000')->firstOrFail(); $barangay = Barangay::where('code', '1380200001')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        // "other": another agent's ticket; "unassigned": visible to every agent; "none": no ticket at all.
        foreach (['other', 'unassigned', 'none'] as $case) {
            $order = Order::create(['source_system' => 'orders', 'external_id' => 'scope-order-'.$case, 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
            $order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Scope item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
            $payment = Payment::create(['order_id' => $order->id, 'customer_id' => $customer->id, 'method' => 'card', 'gateway' => 'sandbox', 'reference' => 'PAY-SCOPE-'.$case, 'total_amount' => 10000, 'captured_amount' => 10000, 'status' => 'captured']);
            $shipment = app(LogisticsService::class)->create(['order_id' => $order->id, 'seller_id' => $seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => Warehouse::where('code', 'demo-las-pinas-hub')->value('id'), 'destination' => ['line1' => 'Scope address', 'region_id' => $city->region_id, 'province_id' => null, 'city_id' => $city->id, 'barangay_id' => $barangay->id, 'postal_code' => '1740', 'contact_name' => 'Scope customer', 'contact_phone' => '09000000000'], 'weight_grams' => 1000, 'dimensions' => ['length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 0, 'tracking_number' => 'SCOPE-'.$case], $admin);
            $ticket = $case === 'none' ? null : app(TicketService::class)->create(['subject' => 'Scope '.$case, 'description' => 'Scope test ticket.', 'requester_type' => 'customer', 'requester_id' => $customer->id, 'order_id' => $order->id, 'payment_id' => $payment->id, 'shipment_id' => $shipment->id, 'channel' => 'email', 'category' => 'delivery'], $admin);
            $this->cases[$case] = compact('order', 'payment', 'shipment', 'ticket');
        }
        $this->cases['other']['ticket']->update(['assignee_id' => User::where('email', 'support.two@example.com')->value('id')]);
    }

    private function as(string $email): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(User::where('email', $email)->firstOrFail()->createToken('scope', ['staff'])->plainTextToken);
    }

    public function test_support_agents_read_only_records_linked_to_their_own_or_unassigned_tickets(): void
    {
        $agent = 'support.one@example.com'; $visible = $this->cases['unassigned']; $hidden = [$this->cases['other'], $this->cases['none']];
        $this->as($agent)->getJson('/api/v1/logistics/shipments')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible['shipment']->id);
        $this->as($agent)->getJson('/api/v1/logistics/shipments/'.$visible['shipment']->id)->assertOk();
        $this->as($agent)->getJson('/api/v1/payments')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible['payment']->id);
        foreach ($hidden as $case) {
            $this->as($agent)->getJson('/api/v1/logistics/shipments/'.$case['shipment']->id)->assertNotFound();
            $this->as($agent)->getJson('/api/v1/logistics/shipments/'.$case['shipment']->id.'/proof-of-delivery/download')->assertNotFound();
            $this->as($agent)->getJson('/api/v1/payments/'.$case['payment']->id)->assertNotFound();
        }
        $this->assertSame([$visible['order']->id], array_column($this->as($agent)->getJson('/api/v1/logistics/options')->assertOk()->json('orders'), 'id'));
        $options = $this->as($agent)->getJson('/api/v1/support/options')->assertOk();
        $this->assertSame([$visible['order']->id], array_column($options->json('orders'), 'id'));
        $this->assertSame([$visible['payment']->id], array_column($options->json('payments'), 'id'));
        $this->assertSame([$visible['shipment']->id], array_column($options->json('shipments'), 'id'));
        $this->as($agent)->getJson('/api/v1/support/tickets/'.$this->cases['other']['ticket']->id)->assertNotFound();
        $this->as('admin@example.com')->getJson('/api/v1/logistics/shipments')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_logistics_coordinators_never_receive_payment_or_requester_data(): void
    {
        $options = $this->as('logistics@example.com')->getJson('/api/v1/support/options')->assertOk();
        $this->assertSame([], $options->json('payments'));
        $this->assertSame([], $options->json('customers'));
        $this->assertCount(2, $options->json('orders'), 'Only orders linked to tickets with shipments.');
        $ticket = $this->cases['unassigned']['ticket'];
        $this->as('logistics@example.com')->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk()->assertJsonMissingPath('payment')->assertJsonPath('shipment.id', $this->cases['unassigned']['shipment']->id);
        $this->as('finance@example.com')->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk()->assertJsonPath('payment.id', $this->cases['unassigned']['payment']->id);
    }

    public function test_audit_metadata_set_through_a_form_request_reaches_the_audit_log(): void
    {
        $id = $this->as('admin@example.com')->postJson('/api/v1/logistics/couriers', ['name' => 'Audited courier', 'code' => 'audited-courier', 'service_types' => ['standard'], 'is_active' => true])->assertCreated()->json('id');
        $entry = AuditLog::latest('id')->firstOrFail();
        $this->assertSame(['Courier', (string) $id], [$entry->entity_type, $entry->entity_id]);
        $this->assertSame('Audited courier', $entry->new_values['name']);
        $this->assertArrayNotHasKey('webhook_secret', $entry->new_values);
        $this->assertSame('created', $entry->action);

        $ticket = $this->cases['unassigned']['ticket'];
        $this->as('admin@example.com')->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'])->assertOk();
        $entry = AuditLog::latest('id')->firstOrFail();
        $this->assertSame(['updated', 'Ticket', (string) $ticket->id, 'open', 'in_progress'], [$entry->action, $entry->entity_type, $entry->entity_id, $entry->old_values['status'], $entry->new_values['status']], 'Actions on an existing record are audited as updates with before and after values.');
    }

    public function test_inbound_webhooks_are_limited_to_300_per_minute_per_gateway_and_per_courier(): void
    {
        foreach (['/api/v1/webhooks/couriers/demo-courier' => 'webhook:courier:demo-courier', '/api/v1/webhooks/payments/sandbox' => 'webhook:gateway:sandbox'] as $uri => $key) {
            $request = Request::create($uri, 'POST');
            $route = app('router')->getRoutes()->match($request)->bind($request);
            $request->setRouteResolver(fn () => $route);
            $this->assertContains('throttle:inbound-webhooks', $route->gatherMiddleware(), $uri);
            $limit = RateLimiter::limiter('inbound-webhooks')($request);
            $this->assertSame([300, $key], [$limit->maxAttempts, $limit->key]);
        }
    }

    public function test_static_routes_resolve_before_identifier_routes(): void
    {
        foreach (['/api/v1/support/options', '/api/v1/payment-options', '/api/v1/logistics/options', '/api/v1/notifications/unread-count'] as $uri) {
            $this->as('admin@example.com')->getJson($uri)->assertOk();
        }
        $sellers = $this->as('admin@example.com')->getJson('/api/v1/billing/options')->assertOk()->json('sellers');
        $this->assertArrayHasKey('payout_destination_masked', $sellers[0]);
        $this->assertArrayNotHasKey('payout_destination', $sellers[0]);
        $this->as('finance@example.com')->postJson('/api/v1/settlements/generate', [])->assertUnprocessable();
    }

    public function test_billing_list_filters_accept_only_statuses_of_that_resource(): void
    {
        $this->as('finance@example.com')->getJson('/api/v1/subscriptions?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->as('finance@example.com')->getJson('/api/v1/plans?status=active')->assertUnprocessable();
        $this->as('finance@example.com')->getJson('/api/v1/invoices?status=paid')->assertOk();
        $this->as('finance@example.com')->getJson('/api/v1/settlements?status=finalized')->assertOk();
        $this->as('finance@example.com')->getJson('/api/v1/payouts?status=paid')->assertOk();
    }

    public function test_billing_events_reach_only_the_active_client_that_owns_the_seller(): void
    {
        $endpoints = [];
        foreach (['owner-system', 'other-system'] as $code) {
            $client = IntegrationClient::create(['name' => $code, 'system_code' => $code, 'is_active' => true]);
            $endpoints[$code] = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/'.$code, 'events' => WebhookEndpoint::EVENTS, 'secret' => 'secret-'.$code, 'is_active' => true]);
        }
        $plan = Plan::create(['name' => 'Scope plan', 'code' => 'scope-plan', 'monthly_price_amount' => 0, 'annual_price_amount' => 0, 'trial_days' => 0, 'features' => [], 'limits' => [], 'is_active' => true]);
        $billing = app(BillingService::class);
        $owned = Seller::create(['source_system' => 'owner-system', 'external_id' => 'owned-seller', 'shop_name' => 'Owned shop']);
        $billing->subscribe(['seller_id' => $owned->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly']);
        $settlement = $billing->generate(now('Asia/Manila')->toDateString(), now('Asia/Manila')->toDateString(), $owned->id)[0];
        $billing->settlementAction($settlement, 'finalize');
        $this->assertEqualsCanonicalizing(['subscription.created', 'invoice.paid', 'settlement.finalized'], WebhookDelivery::where('endpoint_id', $endpoints['owner-system']->id)->pluck('event_type')->all());
        $this->assertSame(0, WebhookDelivery::where('endpoint_id', $endpoints['other-system']->id)->count(), 'Private billing events are never broadcast to other clients.');

        $before = WebhookDelivery::count();
        $orphan = Seller::create(['source_system' => 'no-client-system', 'external_id' => 'orphan-seller', 'shop_name' => 'Orphan shop']);
        $billing->subscribe(['seller_id' => $orphan->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly']);
        IntegrationClient::where('system_code', 'owner-system')->update(['is_active' => false]);
        $billing->cancel(\App\Models\Subscription::where('seller_id', $owned->id)->firstOrFail(), true);
        $this->assertSame($before, WebhookDelivery::count(), 'Sellers without an active owning client produce no deliveries.');
    }
}