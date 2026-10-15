<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Seller;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Read financial data of every storefront (read-only)": integration_clients.reads_all_financials widens only the
// analytics summary, seller subscriptions and seller statements reads. Everything else stays on the client's own source system.
class FinanceReadAccessTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->plan = Plan::create(['name' => 'Basic', 'code' => 'basic', 'monthly_price_amount' => 49900, 'annual_price_amount' => 499000, 'features' => [], 'limits' => []]);
        // The same seller external id exists in both storefronts; each storefront also has one seller of its own.
        foreach (['store-a' => 10000, 'store-b' => 70000] as $system => $amount) {
            $this->seller($system, 'sel-shared', $amount);
            $this->seller($system, 'sel-'.$system, $amount);
        }
    }

    private function seller(string $system, string $externalId, int $amount): void
    {
        $seller = Seller::create(['source_system' => $system, 'external_id' => $externalId, 'shop_name' => "{$externalId} of {$system}"]);
        $customer = Customer::firstOrCreate(['source_system' => $system, 'external_id' => 'cus-'.$system], ['name' => 'Customer of '.$system]);
        $order = Order::create(['source_system' => $system, 'external_id' => "ord-{$externalId}", 'customer_id' => $customer->id, 'subtotal_amount' => $amount, 'total_amount' => $amount, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'product_name' => 'Item', 'quantity' => 1, 'unit_price_amount' => $amount, 'line_total_amount' => $amount]);
        Payment::create(['order_id' => $order->id, 'customer_id' => $customer->id, 'method' => 'card', 'gateway' => 'sandbox', 'status' => 'captured', 'total_amount' => $amount, 'captured_amount' => $amount, 'captured_at' => now(), 'reference' => "PAY-{$system}-{$externalId}"]);
        Subscription::create(['seller_id' => $seller->id, 'plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        Settlement::create(['seller_id' => $seller->id, 'reference' => "STL-{$system}-{$externalId}", 'period_start' => now()->subWeek()->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'gross_sales_amount' => $amount, 'net_payable_amount' => $amount, 'status' => 'finalized', 'finalized_at' => now()]);
    }

    private function token(string $system, bool $readsAll, array $scopes = ['subscriptions:read', 'commissions:read', 'analytics:read']): string
    {
        $client = IntegrationClient::create(['name' => $system, 'system_code' => $system, 'is_active' => true, 'reads_all_financials' => $readsAll]);

        return $client->createToken('test', $scopes)->plainTextToken;
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token);
    }

    public function test_a_normal_client_reads_only_its_own_storefronts_financials(): void
    {
        $token = $this->token('store-a', false);
        $this->as($token)->getJson('/api/v1/integrations/analytics/summary')->assertOk()->assertJsonPath('gmv_amount', 20000)->assertJsonPath('captured_amount', 20000);
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-a')->assertOk()->assertJsonPath('total', 1);
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-b')->assertNotFound();
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-b&source_system=store-b')->assertNotFound();
        $this->as($token)->getJson('/api/v1/integrations/sellers/sel-store-b/statements?source_system=store-b')->assertNotFound();
        // The shared id is never ambiguous for a normal client: it means the client's own seller.
        $this->as($token)->getJson('/api/v1/integrations/sellers/sel-shared/statements')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reference', 'STL-store-a-sel-shared');
    }

    public function test_a_read_all_client_reads_every_storefronts_financials_and_must_pick_an_ambiguous_seller(): void
    {
        $token = $this->token('finance-gl', true);
        $this->as($token)->getJson('/api/v1/integrations/analytics/summary')->assertOk()->assertJsonPath('gmv_amount', 160000)->assertJsonPath('captured_amount', 160000)->assertJsonPath('active_subscriptions', 4);
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-b')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.plan.code', 'basic');
        $this->as($token)->getJson('/api/v1/integrations/sellers/sel-store-b/statements')->assertOk()->assertJsonPath('data.0.reference', 'STL-store-b-sel-store-b')->assertJsonPath('summary.net_payable_amount', 70000);
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-shared')->assertUnprocessable()->assertJsonValidationErrors('source_system');
        $this->as($token)->getJson('/api/v1/integrations/sellers/sel-shared/statements')->assertUnprocessable()->assertJsonValidationErrors('source_system');
        $this->as($token)->getJson('/api/v1/integrations/sellers/sel-shared/statements?source_system=store-b')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reference', 'STL-store-b-sel-shared');
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-shared&source_system=store-a')->assertOk()->assertJsonPath('total', 1);
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-shared&source_system='.str_repeat('x', 81))->assertUnprocessable()->assertJsonValidationErrors('source_system');
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-missing')->assertNotFound();
    }

    public function test_read_all_never_widens_writes_tickets_or_payment_lookups(): void
    {
        $token = $this->token('finance-gl', true, IntegrationClient::SCOPES);
        $customer = Customer::where('source_system', 'store-b')->firstOrFail();
        $ticket = Ticket::create(['reference' => 'TCK-STORE-B', 'subject' => 'Store B ticket', 'description' => 'Not for the finance system.', 'source_system' => 'store-b', 'channel' => 'email', 'category' => 'payment', 'priority' => 'normal', 'requester_type' => 'customer', 'requester_id' => $customer->id, 'first_response_due_at' => now()->addDay(), 'resolution_due_at' => now()->addDays(2)]);
        $this->as($token)->getJson('/api/v1/integrations/payments/PAY-store-b-sel-store-b')->assertNotFound();
        $this->as($token)->getJson('/api/v1/integrations/tickets/'.$ticket->reference)->assertNotFound();
        $this->as($token)->postJson('/api/v1/integrations/orders/ord-sel-store-b/cancel')->assertNotFound();
        $this->as($token)->putJson('/api/v1/integrations/sellers/sel-store-b', ['shop_name' => 'Renamed'])->assertCreated()->assertJsonPath('source_system', 'finance-gl');
        $this->assertSame('sel-store-b of store-b', Seller::where('source_system', 'store-b')->where('external_id', 'sel-store-b')->value('shop_name'));
        $this->assertSame('placed', Order::where('external_id', 'ord-sel-store-b')->value('status_snapshot'));
    }

    public function test_only_admins_can_turn_read_all_on_through_the_client_api(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'read-all.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $admin->assignRole('Admin');
        $finance = User::create(['name' => 'Accounting Staff', 'email' => 'read-all.finance@example.com', 'password' => 'password', 'is_active' => true]);
        $finance->assignRole('Accounting Staff');
        $adminToken = $admin->createToken('staff', ['staff'])->plainTextToken;
        $financeToken = $finance->createToken('staff', ['staff'])->plainTextToken;

        $this->postJson('/api/v1/integration-clients', ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => true])->assertUnauthorized();
        $this->as($financeToken)->postJson('/api/v1/integration-clients', ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => true])->assertForbidden();
        $this->as($adminToken)->postJson('/api/v1/integration-clients', ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('reads_all_financials');
        $plain = $this->as($adminToken)->postJson('/api/v1/integration-clients', ['name' => 'Plain', 'system_code' => 'plain'])->assertCreated()->assertJsonPath('reads_all_financials', false)->json('id');
        $id = $this->as($adminToken)->postJson('/api/v1/integration-clients', ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => true])->assertCreated()->assertJsonPath('reads_all_financials', true)->json('id');
        $this->as($financeToken)->putJson('/api/v1/integration-clients/'.$plain, ['name' => 'Plain', 'system_code' => 'plain', 'reads_all_financials' => true])->assertForbidden();
        $this->assertFalse(IntegrationClient::findOrFail($plain)->reads_all_financials);
        $this->as($adminToken)->getJson('/api/v1/integration-clients?search=ledger')->assertOk()->assertJsonPath('data.0.reads_all_financials', true);
        $this->as($adminToken)->putJson('/api/v1/integration-clients/'.$id, ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => false])->assertOk()->assertJsonPath('reads_all_financials', false);

        // Turning it off takes effect on the client's next read.
        $token = IntegrationClient::findOrFail($id)->createToken('read', ['subscriptions:read'])->plainTextToken;
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-a')->assertNotFound();
        $this->as($adminToken)->putJson('/api/v1/integration-clients/'.$id, ['name' => 'Ledger', 'system_code' => 'ledger', 'reads_all_financials' => true])->assertOk();
        $this->as($token)->getJson('/api/v1/integrations/subscriptions?seller=sel-store-a')->assertOk()->assertJsonPath('total', 1);
    }
}
