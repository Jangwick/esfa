<?php

namespace Tests\Feature;

use App\Models\CannedResponse;
use App\Models\City;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Reconciliation;
use App\Models\Seller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BillingService;
use App\Services\LogisticsService;
use App\Services\PaymentService;
use App\Services\TicketService;
use Database\Seeders\BillingDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\SupportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Role::findByName('Admin')->syncPermissions([]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->admin->syncPermissions([]);
    }

    private function headers(?User $user = null, array $abilities = ['staff']): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.($user ?? $this->admin)->createToken('admin-authorization', $abilities)->plainTextToken];
    }

    /** Real domain records, including a case assigned to another agent. */
    private function records(): array
    {
        $this->seed([BillingDemoSeeder::class, LogisticsDemoSeeder::class, SupportDemoSeeder::class]);
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'admin-customer', 'name' => 'Admin authorization customer', 'email' => 'customer@example.com']);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'admin-seller', 'shop_name' => 'Admin authorization shop']);
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'admin-order', 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        $order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Authorization item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $payment = app(PaymentService::class)->create($order, 'card')['payment'];
        $city = City::where('code', '1380200000')->firstOrFail();
        $logistics = app(LogisticsService::class);
        $shipment = $logistics->create([
            'order_id' => $order->id, 'seller_id' => $seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'),
            'service_type' => 'standard', 'origin_warehouse_id' => Warehouse::where('code', 'demo-las-pinas-hub')->value('id'),
            'destination' => ['line1' => 'Authorization delivery address', 'region_id' => $city->region_id, 'province_id' => null, 'city_id' => $city->id, 'barangay_id' => $city->barangays()->value('id'), 'postal_code' => '1740', 'contact_name' => $customer->name, 'contact_phone' => '09000000000'],
            'weight_grams' => 1000, 'dimensions' => ['length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 0, 'tracking_number' => 'ADMIN-AUTH-TRACKING',
        ], $this->admin);
        foreach (['ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery'] as $status) {
            $shipment = $logistics->transition($shipment, ['status' => $status], 'manual', $this->admin);
        }
        $shipment = $logistics->proof($shipment, UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=')), ['recipient_name' => $customer->name, 'cash_collected' => false], $this->admin);
        $tickets = app(TicketService::class);
        $ticket = $tickets->create(['subject' => 'Case assigned to another agent', 'description' => 'Check full Admin access.', 'requester_type' => 'customer', 'requester_id' => $customer->id, 'order_id' => $order->id, 'payment_id' => $payment->id, 'shipment_id' => $shipment->id, 'channel' => 'email', 'category' => 'delivery'], $this->admin);
        $ticket->update(['assignee_id' => User::where('email', 'support.two@example.com')->value('id')]);
        $message = $tickets->message($ticket, ['body' => 'Internal investigation', 'is_internal_note' => true], [UploadedFile::fake()->createWithContent('note.txt', 'Admin attachment access')], $this->admin);
        $refund = app(PaymentService::class)->requestRefund($payment, ['amount' => 100, 'reason' => 'damaged', 'ticket_id' => $ticket->id], User::where('email', 'finance@example.com')->firstOrFail());
        $plan = Plan::create(['name' => 'Authorization plan', 'code' => 'authorization-plan', 'monthly_price_amount' => 10000, 'annual_price_amount' => 100000, 'trial_days' => 0, 'features' => ['Seller tools'], 'limits' => ['max_products' => 20], 'is_active' => true]);
        $billing = app(BillingService::class);
        $subscription = $billing->subscribe(['seller_id' => $seller->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly']);
        $invoice = Invoice::where('subscription_id', $subscription->id)->firstOrFail();
        $day = now('Asia/Manila')->toDateString();
        $settlement = $billing->generate($day, $day, $seller->id)[0];
        $reconciliation = Reconciliation::create(['filename' => 'Authorization report.csv', 'mime_type' => 'text/csv', 'size' => 34, 'content' => base64_encode("date,gateway_reference,amount,fee\n"), 'date_from' => $day, 'date_to' => $day, 'created_by' => $this->admin->id]);
        $item = $reconciliation->items()->create(['payment_id' => $payment->id, 'date' => $day, 'gateway_reference' => $payment->gateway_reference, 'total_amount' => $payment->captured_amount, 'fee_amount' => 0, 'status' => 'missing_in_report']);

        return compact('customer', 'seller', 'order', 'payment', 'shipment', 'ticket', 'message', 'refund', 'plan', 'subscription', 'invoice', 'settlement', 'reconciliation', 'item');
    }

    public function test_admin_authorization_and_profile_do_not_depend_on_seeded_permission_grants(): void
    {
        $newPermission = Permission::create(['name' => 'approve future workflow', 'guard_name' => 'web']);
        $this->assertSame(0, $this->admin->getAllPermissions()->count(), 'No role or direct grants should explain Admin access.');
        foreach (['view payments', 'approve refunds', 'pay settlements', $newPermission->name] as $ability) {
            $this->assertTrue($this->admin->can($ability), $ability);
        }
        $profile = $this->getJson('/api/v1/user', $this->headers())->assertOk()->json();
        $this->assertEqualsCanonicalizing(Permission::where('guard_name', 'web')->pluck('name')->all(), $profile['permissions']);
        $this->getJson('/api/v1/foundation', $this->headers())->assertOk()->assertJsonPath('summary.permissions_count', Permission::where('guard_name', 'web')->count());
        $this->assertFalse(User::where('email', 'finance@example.com')->firstOrFail()->can($newPermission->name));
    }

    public function test_admin_can_open_every_staff_module_list_detail_options_and_export_without_grants(): void
    {
        $records = $this->records();
        config(['sfa.simulator' => true]);
        $lists = [
            'users', 'audit-logs', 'notifications', 'integration-clients', 'webhook-endpoints', 'webhook-deliveries',
            'support/tickets', 'support/kb/categories', 'support/kb/articles', 'support/canned-responses',
            'payments', 'refunds', 'ledger', 'reconciliations', 'risk-reviews',
            'logistics/shipments', 'logistics/couriers', 'logistics/rates', 'logistics/zones', 'logistics/warehouses',
            'locations/regions', 'locations/provinces', 'locations/cities', 'locations/barangays',
            'plans', 'subscriptions', 'invoices', 'commission-rules', 'commissions', 'settlements', 'payouts',
        ];
        foreach ($lists as $path) {
            $this->getJson('/api/v1/'.$path, $this->headers())->assertOk()->assertJsonStructure(['data', 'summary', 'current_page', 'total']);
            $export = $this->get('/api/v1/'.$path.'?export=csv', $this->headers())->assertOk();
            $this->assertStringStartsWith('text/csv', $export->headers->get('Content-Type'), $path);
        }
        foreach (['roles', 'settings', 'notifications', 'notifications/unread-count', 'support/options', 'support/sla-policies', 'payment-options', 'logistics/options', 'billing/options', 'dashboard', 'analytics/overview', 'analytics/charts', 'simulator'] as $path) {
            $this->getJson('/api/v1/'.$path, $this->headers())->assertOk();
        }
        foreach ([
            'support/tickets/'.$records['ticket']->id, 'support/tickets/'.$records['ticket']->id.'/messages',
            'support/kb/articles/1', 'payments/'.$records['payment']->id, 'payments/'.$records['payment']->id.'/receipt',
            'refunds/'.$records['refund']->id, 'reconciliations/'.$records['reconciliation']->id,
            'logistics/shipments/'.$records['shipment']->id, 'subscriptions/'.$records['subscription']->id,
            'invoices/'.$records['invoice']->id, 'settlements/'.$records['settlement']->id,
        ] as $path) {
            $this->getJson('/api/v1/'.$path, $this->headers())->assertOk();
        }
        $this->get('/api/v1/support/attachments/'.$records['message']->attachments()->value('id').'/download', $this->headers())->assertOk()->assertContent('Admin attachment access');
        $this->get('/api/v1/logistics/shipments/'.$records['shipment']->id.'/proof-of-delivery/download', $this->headers())->assertOk();
        $csv = $this->get('/api/v1/support/canned-responses?is_shared=0&export=csv', $this->headers())->assertOk()->streamedContent();
        $this->assertStringContainsString('My follow-up reminder', $csv);
        $this->get('/api/v1/support/sla-policies?export=csv', $this->headers())->assertOk();
        $this->get('/api/v1/analytics/export?report=revenue_by_day', $this->headers())->assertOk();
    }

    public function test_admin_role_takes_precedence_over_support_and_logistics_visibility_restrictions(): void
    {
        $records = $this->records();
        $this->admin->assignRole(['Administrative Assistant', 'Warehouseman']);
        $this->getJson('/api/v1/support/tickets/'.$records['ticket']->id, $this->headers())->assertOk()
            ->assertJsonPath('payment.id', $records['payment']->id)->assertJsonPath('shipment.id', $records['shipment']->id)
            ->assertJsonPath('requester.email', $records['customer']->email);
        foreach (['support/tickets' => $records['ticket'], 'payments' => $records['payment'], 'refunds' => $records['refund'], 'logistics/shipments' => $records['shipment']] as $path => $record) {
            $list = $this->getJson('/api/v1/'.$path, $this->headers())->assertOk();
            $this->assertContains($record->id, array_column($list->json('data'), 'id'), $path);
            $this->getJson('/api/v1/'.$path.'/'.$record->id, $this->headers())->assertOk();
            $export = $this->get('/api/v1/'.$path.'?export=csv', $this->headers())->assertOk()->streamedContent();
            $this->assertStringContainsString($record->reference, $export, $path);
        }
        $options = $this->getJson('/api/v1/support/options', $this->headers())->assertOk()->json();
        foreach (['customers' => 'customer', 'sellers' => 'seller', 'orders' => 'order', 'payments' => 'payment', 'shipments' => 'shipment'] as $key => $record) {
            $this->assertContains($records[$record]->id, array_column($options[$key], 'id'), $key);
        }
        foreach (['payment-options', 'logistics/options'] as $path) {
            $this->assertContains($records['order']->id, array_column($this->getJson('/api/v1/'.$path, $this->headers())->assertOk()->json('orders'), 'id'));
        }
        $this->getJson('/api/v1/dashboard', $this->headers())->assertOk()->assertJsonStructure(['summary' => ['gmv_amount', 'captured_amount', 'active_subscriptions', 'open_tickets', 'shipments_in_transit']]);
        $this->getJson('/api/v1/analytics/charts', $this->headers())->assertOk();
        $agent = User::where('email', 'support.one@example.com')->firstOrFail();
        $this->getJson('/api/v1/support/tickets/'.$records['ticket']->id, $this->headers($agent))->assertNotFound();
        $this->getJson('/api/v1/payments/'.$records['payment']->id, $this->headers($agent))->assertNotFound();
        $this->getJson('/api/v1/logistics/shipments/'.$records['shipment']->id, $this->headers($agent))->assertNotFound();
    }

    public function test_admin_can_perform_privileged_actions_and_validation_still_applies(): void
    {
        $records = $this->records();
        $policies = config('sfa.settings.sla_policies');
        $policies['urgent']['first_response_hours'] = 2;
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->headers())->assertOk();
        $this->putJson('/api/v1/support/sla-policies', ['policies' => []], $this->headers())->assertUnprocessable();
        $personal = CannedResponse::where('is_shared', false)->firstOrFail();
        $this->putJson('/api/v1/support/canned-responses/'.$personal->id, ['title' => 'Admin updated personal response'], $this->headers())->assertOk();
        $planData = $records['plan']->only(['name', 'code', 'monthly_price_amount', 'annual_price_amount', 'trial_days', 'features', 'limits', 'is_active']);
        $planData['name'] = 'Admin updated plan';
        $this->putJson('/api/v1/plans/'.$records['plan']->id, $planData, $this->headers())->assertOk();
        $courier = Courier::firstOrFail();
        $courierData = $courier->only(['name', 'code', 'service_types', 'tracking_url_template', 'is_active', 'volumetric_divisor']);
        $courierData['name'] = 'Admin updated courier';
        $this->putJson('/api/v1/logistics/couriers/'.$courier->id, $courierData, $this->headers())->assertOk();
        $this->postJson('/api/v1/refunds/'.$records['refund']->id.'/approve', [], $this->headers())->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson('/api/v1/reconciliation-items/'.$records['item']->id.'/resolve', ['notes' => 'Admin confirmed the report exception.'], $this->headers())->assertOk();
        $this->postJson('/api/v1/settlements/'.$records['settlement']->id.'/pay', ['method' => 'gcash', 'destination_masked' => '•••• 1234'], $this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/settlements/'.$records['settlement']->id.'/finalize', [], $this->headers())->assertOk()->assertJsonPath('status', 'finalized');
        $this->postJson('/api/v1/settlements/'.$records['settlement']->id.'/pay', ['method' => 'gcash', 'destination_masked' => '•••• 1234'], $this->headers())->assertOk()->assertJsonPath('status', 'paid');
        $this->putJson('/api/v1/settings', ['company_name' => 'Admin authorization company'], $this->headers())->assertOk();
        $this->postJson('/api/v1/integration-clients', ['name' => 'Admin integration', 'system_code' => 'admin-integration', 'is_active' => true], $this->headers())->assertCreated();
        $agent = User::where('email', 'support.one@example.com')->firstOrFail();
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->headers($agent))->assertForbidden();
        $this->postJson('/api/v1/settlements/'.$records['settlement']->id.'/finalize', [], $this->headers($agent))->assertForbidden();
        $this->postJson('/api/v1/integration-clients', ['name' => 'Unauthorized integration', 'system_code' => 'unauthorized', 'is_active' => true], $this->headers($agent))->assertForbidden();
    }

    public function test_admin_receives_approval_risk_and_shipment_notifications_without_permission_grants(): void
    {
        $records = $this->records();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'refund_awaiting_approval']);
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'admin-risk-order', 'customer_id' => $records['customer']->id, 'subtotal_amount' => 6000000, 'total_amount' => 6000000, 'placed_at' => now()]);
        $payment = app(PaymentService::class)->create($order, 'card')['payment'];
        $this->assertSame('review', $payment->risk_level);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'risk_review_needed']);
        $this->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear', [], $this->headers())->assertOk()->assertJsonPath('risk_level', 'none')->assertJsonPath('status', 'captured');
        $this->postJson('/api/v1/payments/'.$payment->id.'/capture', [], $this->headers())->assertUnprocessable();
        $records['shipment']->update(['status' => 'out_for_delivery', 'delivered_at' => null]);
        app(LogisticsService::class)->transition($records['shipment']->fresh(), ['status' => 'failed_attempt', 'note' => 'Recipient unavailable'], 'manual', $this->admin);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'shipment_failed_attempt']);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('type', 'refund_awaiting_approval')->count());
    }

    public function test_admin_still_requires_an_active_authenticated_staff_session(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->getJson('/api/v1/payments', $this->headers($this->admin, ['payments:read']))->assertForbidden();
        $client = IntegrationClient::create(['name' => 'External client', 'system_code' => 'external', 'is_active' => true]);
        Auth::forgetGuards();
        $this->getJson('/api/v1/payments', ['Authorization' => 'Bearer '.$client->createToken('external', ['staff'])->plainTextToken])->assertForbidden();
        $this->getJson('/api/v1/integrations/analytics/summary', $this->headers())->assertForbidden();
        $this->admin->update(['is_active' => false]);
        $this->getJson('/api/v1/payments', $this->headers())->assertUnauthorized();
    }
}
