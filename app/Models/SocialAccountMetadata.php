<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccountMetadata extends Model
{
    use HasFactory;

    protected $table = 'social_account_metadata';

    protected $fillable = [
        'social_account_id',
        'meta_key',
        'meta_value',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
