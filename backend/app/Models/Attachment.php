<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = [];
    protected $hidden = ['content'];
    protected $casts = ['size' => 'integer'];
    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function message() { return $this->belongsTo(TicketMessage::class, 'ticket_message_id'); }
    public function attachable() { return $this->morphTo(); }
}
