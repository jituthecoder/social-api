<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccountToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
    ];

    /**
     * Tokens MUST be hidden from array and JSON serialization.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Tokens MUST be encrypted at rest in the database.
     */
    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'scopes' => 'array',
    ];


    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
