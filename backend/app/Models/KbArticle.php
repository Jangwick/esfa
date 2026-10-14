<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KbArticle extends Model
{
    protected $guarded = [];
    protected $casts = ['published_at' => 'datetime', 'helpful_count' => 'integer', 'not_helpful_count' => 'integer'];
    public function category() { return $this->belongsTo(KbCategory::class, 'category_id'); }
}
