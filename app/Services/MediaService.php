<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    public function uploadMedia(Workspace $workspace, User $user, UploadedFile $file): Media
    {
        $disk = config('filesystems.default', 'public');
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;

        $folder = 'workspaces/' . $workspace->id . '/' . (str_starts_with($mimeType, 'video/') ? 'videos' : 'images');
        
        $path = false;
        try {
            $path = $file->storeAs($folder, $filename, $disk);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Storage disk [{$disk}] storeAs failed: " . $e->getMessage());
            $path = false;
        }

        // If cloud disk (e.g. S3) failed or credentials missing, gracefully fallback to local public disk
        if (!$path && $disk !== 'public') {
            $disk = 'public';
            try {
                $path = $file->storeAs($folder, $filename, 'public');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Public disk fallback storeAs failed: " . $e->getMessage());
            }
        }

        if (!$path) {
            throw new \RuntimeException('Failed to store uploaded file on disk.');
        }

        // Generate URL safely without calling AWS with empty or invalid keys
        $url = null;
        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to generate URL for disk [{$disk}] path [{$path}]: " . $e->getMessage());
        }

        if (!$url || ($disk === 'public' && !str_starts_with($url, 'http'))) {
            $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
            $url = $baseUrl . '/storage/' . ltrim($path, '/');
        }

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'path' => $path,
            'url' => $url,
            'processing_status' => 'ready',
            'metadata' => [
                'disk' => $disk,
                'extension' => $extension,
                'local_path' => $disk === 'public' ? storage_path('app/public/' . $path) : null,
            ],
        ]);

        $this->auditLogService->log('media.uploaded', $workspace, $user, [
            'media_id' => $media->id,
            'filename' => $media->filename,
            'size' => $media->size,
        ]);

        return $media;
    }

    public function deleteMedia(Media $media, Workspace $workspace, User $user): bool
    {
        $disk = $media->metadata['disk'] ?? config('filesystems.default', 'public');
        if (!empty($media->path) && trim($media->path) !== '') {
            try {
                if (Storage::disk($disk)->exists($media->path)) {
                    Storage::disk($disk)->delete($media->path);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to delete media path [{$media->path}] from disk [{$disk}]: " . $e->getMessage());
            }
        }

        $this->auditLogService->log('media.deleted', $workspace, $user, ['media_id' => $media->id]);

        return $media->delete();
    }
}
