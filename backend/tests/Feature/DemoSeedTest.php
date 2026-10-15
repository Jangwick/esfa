<?php

namespace Tests\Feature;

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\IntegrationClient;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    private function seedAs(string $environment): void
    {
        $this->app['env'] = $environment;
        try { $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful(); } finally { $this->app['env'] = 'testing'; }
    }

    public function test_development_seeding_builds_the_complete_demo_dataset_and_is_idempotent(): void
    {
        $this->seedAs('local');

        $this->assertSame(20, User::count());
        $this->assertGreaterThanOrEqual(60, Order::count());
        $this->assertSame(['demo-storefront'], Order::distinct()->pluck('source_system')->all());
        $this->assertGreaterThanOrEqual(40, (int) now()->diffInDays(Order::min('placed_at'), true));
        $this->assertEqualsCanonicalizing(Payment::STATUSES, Payment::distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['card', 'gcash', 'maya', 'bank_transfer', 'cod'], Payment::whereNull('invoice_id')->distinct()->pluck('method')->all());
        $this->assertEqualsCanonicalizing(Ticket::STATUSES, Ticket::distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['on_track', 'at_risk', 'breached', 'met'], Ticket::all()->pluck('sla_state')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(Shipment::STATUSES, Shipment::distinct()->pluck('status')->all());
        $this->assertGreaterThan(Shipment::count(), \App\Models\ShipmentEvent::count());
        $this->assertGreaterThan(0, Shipment::whereNotNull('proof_attachment_id')->count());
        $this->assertGreaterThan(0, Shipment::where('cash_collected', true)->count());
        $this->assertTrue(Payment::where('method', 'cod')->where('status', 'captured')->exists(), 'COD cash collection captures the COD payment.');
        $this->assertEqualsCanonicalizing(['demo-free', 'demo-growth', 'demo-pro'], Plan::pluck('code')->all());
        $this->assertEqualsCanonicalizing(['trialing', 'active', 'past_due', 'cancelled', 'expired'], Subscription::distinct()->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['global', 'category', 'plan', 'seller'], CommissionRule::distinct()->pluck('scope')->all());
        $this->assertEqualsCanonicalizing(['capture', 'reversal'], CommissionEntry::distinct()->pluck('entry_type')->all());
        $this->assertSame(2, Settlement::distinct()->count('period_start'));
        $this->assertEqualsCanonicalizing(['finalized', 'paid'], Settlement::distinct()->pluck('status')->all());
        $this->assertTrue(Payout::exists());
        $this->assertSame(0, Payout::where('destination_masked', 'not like', '••••%')->count());
        $this->assertTrue(Payment::where('risk_level', 'review')->exists());
        $this->assertSame(6, IntegrationClient::count());
        $this->assertSame(['http://localhost:8787/webhooks' => false], WebhookEndpoint::pluck('is_active', 'url')->all());
        $this->assertSame((int) LedgerEntry::where('direction', 'debit')->sum('total_amount'), (int) LedgerEntry::where('direction', 'credit')->sum('total_amount'));

        $counts = [Order::count(), Shipment::count(), Ticket::count(), Settlement::count(), PersonalAccessToken::count()];
        $this->seedAs('local');
        $this->assertSame($counts, [Order::count(), Shipment::count(), Ticket::count(), Settlement::count(), PersonalAccessToken::count()], 'Re-seeding must not duplicate demo records or tokens.');
    }

    public function test_production_seeding_creates_only_roles_and_settings(): void
    {
        $this->seedAs('production');

        $this->assertSame(19, Role::count());
        $this->assertSame(0, User::count());
        foreach ([Order::class, Payment::class, Ticket::class, Shipment::class, Plan::class, CommissionRule::class, IntegrationClient::class, WebhookEndpoint::class, PersonalAccessToken::class] as $model) {
            $this->assertSame(0, $model::count(), $model.' must never be seeded in production.');
        }
    }
}