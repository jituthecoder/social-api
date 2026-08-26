<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'platform',
        'content',
        'hashtags',
        'metadata',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
