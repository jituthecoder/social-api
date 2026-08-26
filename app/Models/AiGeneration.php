<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'prompt_type',
        'model',
        'input_prompt',
        'generated_content',
        'token_usage',
        'estimated_cost',
        'status',
    ];

    protected $casts = [
        'token_usage' => 'integer',
        'estimated_cost' => 'decimal:4',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
