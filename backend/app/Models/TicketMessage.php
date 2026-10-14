<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $guarded = [];
    protected $casts = ['is_internal_note' => 'boolean'];
    protected $appends = ['author_name'];

    /** Staff replies show the agent's name; customer, seller and system messages are labelled by type. */
    public function getAuthorNameAttribute(): ?string
    {
        return $this->author_type === 'user' && $this->author_id ? User::whereKey($this->author_id)->value('name') : null;
    }
    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function attachments() { return $this->hasMany(Attachment::class); }
    public function author() { return $this->belongsTo(User::class, 'author_id'); }
}
