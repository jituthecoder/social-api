<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'filename',
        'original_name',
        'mime_type',
        'size',
        'path',
        'url',
        'processing_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function getBinaryContent(): ?string
    {
        $disk = $this->metadata['disk'] ?? config('filesystems.default', 'public');
        if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($this->path)) {
            return \Illuminate\Support\Facades\Storage::disk($disk)->get($this->path);
        }

        if (!empty($this->metadata['local_path']) && file_exists($this->metadata['local_path'])) {
            return file_get_contents($this->metadata['local_path']);
        }

        if (filter_var($this->url, FILTER_VALIDATE_URL)) {
            $content = @file_get_contents($this->url);
            if ($content !== false) {
                return $content;
            }
        }

        return null;
    }

    public function getLocalFilePath(): ?string
    {
        $disk = $this->metadata['disk'] ?? config('filesystems.default', 'public');
        if ($disk === 'public') {
            $fullPath = storage_path('app/public/' . $this->path);
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        if (!empty($this->metadata['local_path']) && file_exists($this->metadata['local_path'])) {
            return $this->metadata['local_path'];
        }

        return null;
    }
}
