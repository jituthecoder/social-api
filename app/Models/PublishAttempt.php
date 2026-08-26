<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'post_variant_id',
        'social_account_id',
        'status',
        'error_code',
        'error_message',
        'raw_response',
        'attempted_at',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'attempted_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
