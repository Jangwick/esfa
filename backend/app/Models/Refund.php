<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
class Refund extends Model {
    public const REASONS = ['item_not_received','damaged','wrong_item','cancelled_order','duplicate_charge','other'];
    public const PHOTO_TYPES = ['image/png','image/jpeg','image/webp'];
    protected $guarded = [];
    protected $appends = ['amount'];
    protected function casts(): array { return ['total_amount'=>'integer','requested_by'=>'integer','approved_by'=>'integer','approved_at'=>'datetime','completed_at'=>'datetime']; }
    public function getAmountAttribute(): int { return (int)$this->total_amount; }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function requester() { return $this->belongsTo(User::class,'requested_by'); }
    // Files added to the staff refund request (AttachmentService::store with this refund as the owner).
    public function attachments() { return $this->morphMany(Attachment::class,'attachable'); }

    /**
     * The one place that decides which photos count as evidence for this refund (the AI check and GET /refunds/{refund}/photos/{attachment}):
     * PNG, JPEG or WebP files up to 4 MB, first those added to the refund request, then those in the messages of its tickets
     * (its own ticket and the tickets of its payment or of that payment's order), each group newest first, at most $limit.
     * Each item: id, filename, mime_type, size, content (base64; left out when $content is false) and source ('Refund request' or the ticket reference).
     */
    public function evidencePhotos(int $limit = 4, bool $content = true): Collection {
        $columns = ['id','filename','mime_type','size','ticket_id',...($content ? ['content'] : [])];
        $fields = ['id','filename','mime_type','size',...($content ? ['content'] : [])];
        $images = fn ($query) => $query->whereIn('mime_type', self::PHOTO_TYPES)->where('size', '<=', 4 * 1024 * 1024)->latest('id')->limit($limit)->get($columns);
        $own = $images($this->attachments())->map(fn ($file) => [...$file->only($fields), 'source' => 'Refund request']);
        $orderId = $this->payment?->order_id;
        $tickets = Ticket::where(fn ($query) => $query->where('payment_id', $this->payment_id)->when($this->ticket_id, fn ($query) => $query->orWhere('id', $this->ticket_id))->when($orderId, fn ($query) => $query->orWhere('order_id', $orderId)))->pluck('reference', 'id');
        $chat = $own->count() >= $limit || $tickets->isEmpty() ? collect() : $images(Attachment::whereIn('ticket_id', $tickets->keys())->whereNotNull('ticket_message_id'))->map(fn ($file) => [...$file->only($fields), 'source' => $tickets[$file->ticket_id] ?: 'Support conversation']);

        return $own->concat($chat)->take($limit)->values();
    }
}
