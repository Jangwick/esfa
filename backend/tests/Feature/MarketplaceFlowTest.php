<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Barangay;
use App\Models\City;
use App\Models\CommissionEntry;
use App\Models\Courier;
use App\Models\IntegrationClient;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Zone;
use App\Services\Scheduler;
use Database\Seeders\BillingDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email): string
    {
        return User::where('email', $email)->firstOrFail()->createToken('flow-staff', ['staff'])->plainTextToken;
    }

    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_marketplace_order_to_payment_delivery_support_refund_settlement_and_signed_webhooks(): void
    {
        $this->travelTo(now()->subDays(2)->startOfSecond());
        $this->seed([DatabaseSeeder::class, BillingDemoSeeder::class]);
        $admin = $this->token('admin@example.com');
        $support = $this->token('support.one@example.com');
        $finance = $this->token('finance@example.com');
        $logistics = $this->token('logistics@example.com');
        $client = IntegrationClient::create(['name' => 'Flow marketplace', 'system_code' => 'flow-marketplace', 'is_active' => true]);
        $integration = $client->createToken('flow', IntegrationClient::SCOPES)->plainTextToken;
        $hook = WebhookEndpoint::create([
            'client_id' => $client->id, 'url' => 'https://8.8.8.8/flow-hook', 'events' => WebhookEndpoint::EVENTS,
            'secret' => 'flow-known-secret', 'is_active' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://8.8.8.8/*' => Http::response('', 204)]);

        $this->asToken($integration)->putJson('/api/v1/integrations/customers/customer-flow', [
            'name' => 'Flow customer', 'email' => 'flow.customer@example.com',
        ])->assertCreated();
        $sellerResponse = $this->asToken($integration)->putJson('/api/v1/integrations/sellers/seller-flow', [
            'shop_name' => 'Flow shop', 'owner_name' => 'Flow seller', 'email' => 'flow.seller@example.com',
            'payout_destination' => ['bank_name' => 'Demo bank', 'account_number' => '000000001234', 'account_name' => 'Flow seller'],
        ])->assertCreated()->assertJsonPath('payout_destination_masked', '•••• 1234');
        $sellerId = $sellerResponse->json('id');
        $this->assertArrayNotHasKey('payout_destination', $sellerResponse->json());
        $this->asToken($integration)->putJson('/api/v1/integrations/categories/category-flow', ['name' => 'Flow category'])->assertCreated();
        $order = $this->asToken($integration)->putJson('/api/v1/integrations/orders/order-flow', [
            'customer_external_id' => 'customer-flow', 'status_snapshot' => 'placed', 'subtotal_amount' => 10000,
            'shipping_amount' => 1000, 'discount_amount' => 0, 'total_amount' => 11000, 'placed_at' => now()->toIso8601String(),
            'items' => [['seller_external_id' => 'seller-flow', 'category_external_id' => 'category-flow', 'product_name' => 'Flow item',
                'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]],
        ])->assertCreated();
        $payment = $this->asToken($integration)->withHeader('Idempotency-Key', 'flow-payment')->postJson('/api/v1/integrations/payments', [
            'order_external_id' => 'order-flow', 'method' => 'card',
        ])->assertCreated()->assertJsonPath('payment.status', 'captured')->json('payment');
        $this->assertSame(500, (int) CommissionEntry::where('payment_id', $payment['id'])->where('entry_type', 'capture')->sum('commission_amount'));

        // Small verified PSGC fixtures; no domain demo seeder is needed for the flow.
        $region = Region::create(['code' => '1300000000', 'name' => 'National Capital Region (NCR)']);
        $city = City::create(['code' => '1380200000', 'name' => 'City of Las Piñas', 'type' => 'city', 'region_id' => $region->id]);
        $barangay = Barangay::create(['code' => '1380200001', 'name' => 'Almanza Uno', 'city_id' => $city->id]);
        $addressValues = ['line1' => 'Flow address', 'region_id' => $region->id, 'province_id' => null, 'city_id' => $city->id,
            'barangay_id' => $barangay->id, 'postal_code' => '1740', 'contact_name' => 'Flow customer', 'contact_phone' => '09000000000'];
        $address = Address::create($addressValues);
        $warehouse = Warehouse::create(['name' => 'Flow hub', 'code' => 'flow-hub', 'type' => 'platform_hub', 'address_id' => $address->id, 'is_active' => true]);
        $courier = Courier::create(['name' => 'Flow sandbox courier', 'code' => 'flow-courier', 'service_types' => ['standard'], 'webhook_secret' => 'flow-courier-secret', 'is_active' => true]);
        $zone = Zone::create(['name' => 'Flow zone', 'min_days' => 1, 'max_days' => 3, 'province_ids' => [], 'city_ids' => [$city->id], 'is_active' => true]);
        Rate::create(['courier_id' => $courier->id, 'service_type' => 'standard', 'origin_zone_id' => $zone->id, 'destination_zone_id' => $zone->id,
            'base_weight_grams' => 1000, 'base_fee_amount' => 1000, 'additional_per_500g_amount' => 100, 'cod_fee_bp' => 0, 'max_weight_grams' => 10000, 'is_active' => true]);
        $dimensions = ['length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00'];
        $this->asToken($integration)->postJson('/api/v1/integrations/quotes', [
            'origin_city_id' => $city->id, 'destination_city_id' => $city->id, 'weight_grams' => 1000, 'dimensions' => $dimensions, 'cod_amount' => 0,
        ])->assertOk()->assertJsonPath('data.0.total_amount', 1000);
        $shipment = $this->asToken($integration)->withHeader('Idempotency-Key', 'flow-shipment')->postJson('/api/v1/integrations/shipments', [
            'order_external_id' => 'order-flow', 'seller_external_id' => 'seller-flow', 'courier_id' => $courier->id, 'service_type' => 'standard',
            'origin_warehouse_id' => $warehouse->id, 'destination' => $addressValues, 'weight_grams' => 1000, 'dimensions' => $dimensions,
            'cod_amount' => 0, 'tracking_number' => 'FLOW-TRACKING',
        ])->assertCreated()->assertJsonPath('shipping_fee_amount', 1000)->json();
        foreach (['ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery'] as $status) {
            $this->asToken($logistics)->postJson('/api/v1/logistics/shipments/'.$shipment['id'].'/events', [
                'status' => $status, 'note' => 'Flow shipment progressed.',
            ])->assertOk()->assertJsonPath('status', $status);
        }
        $this->asToken($logistics)->post('/api/v1/logistics/shipments/'.$shipment['id'].'/proof-of-delivery', [
            'recipient_name' => 'Flow customer', 'photo' => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII=')), 'note' => 'Delivered to customer.',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'delivered');
        $this->asToken($integration)->getJson('/api/v1/integrations/shipments/'.$shipment['reference'])->assertOk()->assertJsonPath('status', 'delivered');

        $ticket = $this->asToken($integration)->withHeader('Idempotency-Key', 'flow-ticket')->postJson('/api/v1/integrations/tickets', [
            'requester_type' => 'customer', 'requester_external_id' => 'customer-flow', 'order_external_id' => 'order-flow',
            'payment_reference' => $payment['reference'], 'shipment_reference' => $shipment['reference'],
            'subject' => 'Delivered item needs a partial refund', 'description' => 'The delivered item has minor damage.',
            'channel' => 'marketplace_app', 'category' => 'refund_return', 'priority' => 'normal',
        ])->assertCreated()->json();
        $supportId = User::where('email', 'support.one@example.com')->value('id');
        $this->asToken($support)->postJson('/api/v1/support/tickets/'.$ticket['id'].'/assign', ['assignee_id' => $supportId])->assertOk();
        $this->asToken($support)->postJson('/api/v1/support/tickets/'.$ticket['id'].'/messages', [
            'body' => 'We can request a partial refund for the damage.', 'is_internal_note' => false,
        ])->assertCreated();
        $this->asToken($support)->postJson('/api/v1/support/tickets/'.$ticket['id'].'/messages', [
            'body' => 'INTERNAL-FLOW-NOTE', 'is_internal_note' => true,
        ])->assertCreated();
        $public = $this->asToken($integration)->getJson('/api/v1/integrations/tickets/'.$ticket['reference'])->assertOk();
        $this->assertStringNotContainsString('INTERNAL-FLOW-NOTE', $public->getContent());
        $this->assertNotNull(Ticket::findOrFail($ticket['id'])->first_responded_at);
        $refund = $this->asToken($support)->postJson('/api/v1/refunds', [
            'payment_id' => $payment['id'], 'ticket_id' => $ticket['id'], 'amount' => 1100, 'reason' => 'damaged', 'notes' => 'Partial compensation agreed with customer.',
        ])->assertCreated()->json();
        $this->asToken($finance)->postJson('/api/v1/refunds/'.$refund['id'].'/approve')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(1100, Payment::findOrFail($payment['id'])->refunded_amount);
        $this->assertSame(450, (int) CommissionEntry::where('payment_id', $payment['id'])->sum('commission_amount'));

        $date = now()->timezone('Asia/Manila')->toDateString();
        $settlement = $this->asToken($finance)->postJson('/api/v1/settlements/generate', [
            'period_start' => $date, 'period_end' => $date, 'seller_id' => $sellerId,
        ])->assertCreated()->assertJsonPath('data.0.net_payable_amount', 8550)->json('data.0');
        $this->asToken($finance)->postJson('/api/v1/settlements/'.$settlement['id'].'/finalize')->assertOk()->assertJsonPath('status', 'finalized');
        $this->asToken($finance)->postJson('/api/v1/settlements/'.$settlement['id'].'/pay', ['method' => 'bank_transfer'])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('payouts.0.amount', 8550);
        $this->asToken($finance)->postJson('/api/v1/settlements/'.$settlement['id'].'/pay', ['method' => 'bank_transfer'])->assertUnprocessable();
        $this->asToken($integration)->getJson('/api/v1/integrations/sellers/seller-flow/statements')
            ->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('summary.net_payable_amount', 8550);

        $debits = (int) LedgerEntry::where('direction', 'debit')->sum('total_amount');
        $credits = (int) LedgerEntry::where('direction', 'credit')->sum('total_amount');
        $this->assertSame($debits, $credits);
        $this->assertGreaterThan(0, $debits);
        $this->assertSame(1, Shipment::where('status', 'delivered')->count());
        $this->assertGreaterThan(0, WebhookDelivery::where('endpoint_id', $hook->id)->count());
        config(['sfa.scheduler_enabled' => true]); // phpunit.xml pauses scheduled work by default.
        app(Scheduler::class)->run();
        $this->assertSame(0, WebhookDelivery::where('endpoint_id', $hook->id)->where('status', '!=', 'delivered')->count());
        Http::assertSent(function ($request) use ($hook) {
            if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('X-SFA-Signature')[0] ?? '', $signature)) {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $signature[1].'.'.$request->body(), $hook->secret), $signature[2]);
        });
    }
}
