<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    public const STATUSES = ['open', 'in_progress', 'waiting_on_customer', 'resolved', 'closed', 'reopened'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    // 'system' marks tickets SFA opens itself; TicketRequest never accepts it from people or partners.
    public const CHANNELS = ['email', 'chat', 'phone', 'web_form', 'marketplace_app', 'system'];
    public const CATEGORIES = ['order', 'payment', 'delivery', 'refund_return', 'account', 'product_inquiry', 'seller_concern', 'other'];
    protected $guarded = [];
    protected $appends = ['sla_state', 'requester'];
    protected function casts(): array
    {
        return ['assignee_id' => 'integer', 'tags' => 'array', 'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'resolution_paused_at' => 'datetime', 'sla_at_risk_notified_at' => 'datetime', 'sla_breached_notified_at' => 'datetime', 'resolution_paused_seconds' => 'integer', 'csat_score' => 'integer'];
    }
    public function assignee() { return $this->belongsTo(User::class, 'assignee_id'); }
    public function order() { return $this->belongsTo(Order::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function shipment() { return $this->belongsTo(Shipment::class); }
    public function messages() { return $this->hasMany(TicketMessage::class)->orderBy('id'); }
    public function attachments() { return $this->hasMany(Attachment::class); }
    public function getRequesterAttribute(): ?array
    {
        $class = $this->requester_type === 'seller' ? Seller::class : Customer::class;
        $row = $class::find($this->requester_id);

        return $row ? ['id' => $row->id, 'name' => $row->name ?? $row->shop_name, 'email' => $row->email, 'external_id' => $row->external_id] : null;
    }
    public function getSlaStateAttribute(): string
    {
        if (in_array($this->status, ['resolved', 'closed'], true)) {
            return $this->first_responded_at && $this->first_responded_at->lte($this->first_response_due_at) && $this->resolved_at?->lte($this->resolution_due_at) ? 'met' : 'breached';
        }
        $paused = $this->resolution_paused_at ? max(0, (int) $this->resolution_paused_at->diffInSeconds(now())) : 0;
        $due = $this->first_responded_at ? $this->resolution_due_at->copy()->addSeconds($paused) : $this->first_response_due_at;
        if ($due->isPast()) { return 'breached'; }
        $window = $this->first_responded_at ? max(1, (int) $this->created_at->diffInSeconds($this->resolution_due_at) - $this->resolution_paused_seconds) : max(1, (int) $this->created_at->diffInSeconds($this->first_response_due_at));

        return now()->diffInSeconds($due) < $window * 0.25 ? 'at_risk' : 'on_track';
    }
}
