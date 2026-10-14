<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class IntegrationClient extends Authenticatable
{
    use HasApiTokens;

    public const SCOPES = [
        'customers:write', 'sellers:write', 'catalog:write', 'orders:write',
        'payments:read', 'payments:write', 'refunds:write', 'quotes:read',
        'shipments:read', 'shipments:write', 'tickets:read', 'tickets:write',
        'kb:read', 'subscriptions:read', 'commissions:read', 'analytics:read', 'webhooks:manage',
    ];

    protected $fillable = ['name', 'system_code', 'contact_email', 'is_active', 'reads_all_financials'];

    // Read financial data of every storefront (read-only); off unless an Admin turns it on.
    protected $attributes = ['reads_all_financials' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'reads_all_financials' => 'boolean'];
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class, 'client_id');
    }
}
