<?php
namespace App\Models;
class Shipment extends DomainModel
{
    public const STATUSES = ['pending', 'ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt', 'returning', 'returned_to_sender', 'delivered', 'cancelled'];
    public const FINAL_STATUSES = ['delivered', 'returned_to_sender', 'cancelled'];
    protected $appends = ['tracking_url', 'on_time'];
    // Scheduler bookkeeping, kept out of API responses and webhooks.
    protected $hidden = ['overdue_notified_at', 'stuck_notified_at'];
    protected function casts(): array { return ['dimensions' => 'array', 'shipping_fee_amount' => 'integer', 'cod_amount' => 'integer', 'attempt_count' => 'integer', 'cash_collected' => 'boolean', 'delivered_at' => 'datetime', 'promised_from' => 'date:Y-m-d', 'promised_to' => 'date:Y-m-d', 'overdue_notified_at' => 'datetime', 'stuck_notified_at' => 'datetime']; }
    public function order() { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function courier() { return $this->belongsTo(Courier::class); }
    public function originWarehouse() { return $this->belongsTo(Warehouse::class); }
    public function destinationAddress() { return $this->belongsTo(Address::class); }
    public function proofAttachment() { return $this->belongsTo(Attachment::class); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }
    public function events() { return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at')->orderBy('id'); }
    // Still on its way to the customer after its last promised day in the business timezone (a returning parcel is not late).
    public function scopeOverdue($query, ?string $today = null) { return $query->whereNotIn('status', [...self::FINAL_STATUSES, 'returning'])->where('promised_to', '<', $today ?? \Carbon\CarbonImmutable::now(Setting::valueFor('business_timezone'))->toDateString()); }
    public function getTrackingUrlAttribute(): ?string { return $this->tracking_number && $this->courier?->tracking_url_template ? str_replace('{tracking_number}', rawurlencode($this->tracking_number), $this->courier->tracking_url_template) : null; }
    public function getOnTimeAttribute(): ?bool { return $this->delivered_at ? $this->delivered_at->lte(\Carbon\CarbonImmutable::parse($this->promised_to->format('Y-m-d'), Setting::valueFor('business_timezone', 'Asia/Manila'))->endOfDay()->utc()) : null; }
}
