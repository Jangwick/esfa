<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    public const EVENTS = [
        'ticket.created', 'ticket.status_changed', 'ticket.replied', 'payment.authorized',
        'payment.captured', 'payment.failed', 'payment.cancelled', 'payment.disputed',
        'refund.requested', 'refund.approved', 'refund.rejected', 'refund.completed', 'refund.failed',
        'shipment.created', 'shipment.status_changed', 'shipment.delivered', 'subscription.created',
        'subscription.renewed', 'subscription.past_due', 'subscription.cancelled', 'invoice.paid',
        'settlement.finalized', 'payout.paid',
    ];

    protected $fillable = ['client_id', 'url', 'events', 'secret', 'is_active', 'consecutive_failures', 'disabled_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean', 'disabled_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'client_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }
}
