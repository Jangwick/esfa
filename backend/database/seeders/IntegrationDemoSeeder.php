<?php

namespace Database\Seeders;

use App\Models\IntegrationClient;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class IntegrationDemoSeeder extends Seeder
{
    /** One demo client per sibling system. demo-storefront owns every demo mirror record (customers, sellers, orders). */
    public const CLIENTS = [
        'demo-storefront' => ['Demo storefront', ['customers:write', 'sellers:write', 'catalog:write', 'orders:write', 'payments:read', 'payments:write', 'refunds:write', 'quotes:read', 'shipments:read', 'shipments:write', 'tickets:read', 'tickets:write', 'kb:read', 'subscriptions:read', 'commissions:read', 'analytics:read', 'webhooks:manage']],
        'demo-orders' => ['Demo order service', ['customers:write', 'sellers:write', 'orders:write', 'payments:read']],
        'demo-checkout' => ['Demo checkout', ['payments:read', 'payments:write', 'quotes:read']],
        'demo-accounts' => ['Demo accounts', ['customers:write', 'sellers:write', 'tickets:read', 'tickets:write', 'kb:read']],
        'demo-catalog' => ['Demo catalog', ['catalog:write']],
        'demo-finance-gl' => ['Demo finance ledger', ['payments:read', 'subscriptions:read', 'commissions:read', 'analytics:read']],
    ];

    public function run(): void
    {
        // Demo clients, tokens and webhook endpoints are development-only. Production never creates them.
        if (app()->environment('production')) { return; }
        foreach (self::CLIENTS as $code => [$name, $scopes]) {
            $client = IntegrationClient::firstOrCreate(['system_code' => $code], ['name' => $name, 'contact_email' => $code.'@example.com', 'is_active' => true]);
            if ($client->tokens()->where('name', 'demo')->exists()) { continue; }
            // Tokens are stored hashed, so the plain text is printed once, here, for local development.
            $this->command?->line(sprintf('  Demo token for %-16s %s', $code.':', $client->createToken('demo', $scopes)->plainTextToken));
        }
        $storefront = IntegrationClient::where('system_code', 'demo-storefront')->first();
        if (! WebhookEndpoint::where('client_id', $storefront->id)->where('url', 'http://localhost:8787/webhooks')->exists()) {
            WebhookEndpoint::create(['client_id' => $storefront->id, 'url' => 'http://localhost:8787/webhooks', 'events' => WebhookEndpoint::EVENTS, 'secret' => 'whsec_'.Str::random(64), 'is_active' => false]);
        }
    }
}