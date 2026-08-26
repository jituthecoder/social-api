<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'platform',
        'platform_account_id',
        'name',
        'username',
        'account_type',
        'avatar_url',
        'connection_status',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function token(): HasOne
    {
        return $this->hasOne(SocialAccountToken::class);
    }

    public function metadata(): HasMany
    {
        return $this->hasMany(SocialAccountMetadata::class);
    }
}
