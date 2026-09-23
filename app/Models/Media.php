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

    // In-memory cache to avoid re-reading or re-downloading the same media multiple times in one request
    protected ?string $cachedBinary = null;

    public function getBinaryContent(): ?string
    {
        if ($this->cachedBinary !== null) {
            return $this->cachedBinary;
        }

        // 1. FASTEST: Check local storage path on disk (0ms latency, zero cloud network overhead)
        if (!empty($this->path) && trim($this->path) !== '') {
            $localFallback = storage_path('app/public/' . ltrim($this->path, '/'));
            if (file_exists($localFallback) && is_file($localFallback)) {
                $content = @file_get_contents($localFallback);
                if ($content !== false && strlen($content) > 0) {
                    return $this->cachedBinary = $content;
                }
            }
        }

        // 2. Check explicit local path metadata
        if (!empty($this->metadata['local_path']) && file_exists($this->metadata['local_path']) && is_file($this->metadata['local_path'])) {
            $content = @file_get_contents($this->metadata['local_path']);
            if ($content !== false && strlen($content) > 0) {
                return $this->cachedBinary = $content;
            }
        }

        // 3. Cloud Storage (AWS S3, Google Cloud, Cloudflare R2, MinIO)
        if (!empty($this->path) && trim($this->path) !== '') {
            $disk = $this->metadata['disk'] ?? config('filesystems.default', 'public');
            if ($disk !== 'public' && $disk !== 'local') {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($this->path)) {
                        $content = \Illuminate\Support\Facades\Storage::disk($disk)->get($this->path);
                        if ($content !== null && $content !== false && strlen($content) > 0) {
                            return $this->cachedBinary = $content;
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Storage disk [{$disk}] getBinaryContent failed for path [{$this->path}]: " . $e->getMessage());
                }
            }
        }

        // 4. Fallback: Download from public URL via HTTP client with timeout
        if (!empty($this->url) && filter_var($this->url, FILTER_VALIDATE_URL)) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(15)->get($this->url);
                if ($response->successful() && strlen($response->body()) > 0) {
                    return $this->cachedBinary = $response->body();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to fetch binary content from URL [{$this->url}]: " . $e->getMessage());
            }
        }

        return null;
    }

    public function getLocalFilePath(): ?string
    {
        // 1. Direct local path from metadata
        if (!empty($this->metadata['local_path']) && file_exists($this->metadata['local_path']) && is_file($this->metadata['local_path'])) {
            return $this->metadata['local_path'];
        }

        // 2. Direct public storage path
        if (!empty($this->path) && trim($this->path) !== '') {
            $fullPath = storage_path('app/public/' . ltrim($this->path, '/'));
            if (file_exists($fullPath) && is_file($fullPath)) {
                return $fullPath;
            }
        }

        // 3. For Cloud-stored media (AWS S3, Google Cloud, R2):
        // Check if temporary cached file already exists on disk — DO NOT re-download!
        $tempDir = storage_path('app/temp');
        $ext = $this->metadata['extension'] ?? pathinfo($this->filename ?? 'media', PATHINFO_EXTENSION) ?: 'jpg';
        $tempFile = $tempDir . '/' . md5($this->id . ($this->path ?? $this->url ?? 'media')) . '.' . $ext;

        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            return $tempFile;
        }

        // 4. Download once and save to temp cache
        $binary = $this->getBinaryContent();
        if ($binary) {
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0755, true);
            }
            if (file_put_contents($tempFile, $binary) !== false) {
                return $tempFile;
            }
        }

        return null;
    }
}
